<?php
namespace ClarionApp\WizlightBackend;

use ClarionApp\WizlightBackend\LightColor;
use ClarionApp\WizlightBackend\Models\Bulb;

class Wiz
{
    private $broadcast_address;
    private $wait_time;
    private $local_ip;

    public function __construct($wait_time = 30.0, $broadcast_address = "255.255.255.255")
    {
        $this->broadcast_address = $broadcast_address;
        $this->wait_time = $wait_time;
        $this->local_ip = $this->get_local_ip();
    }

    public function discover() : array
    {
        $bulbs = [];

        $message = new \stdClass();
        $message->method = 'registration';
        $message->params = new \stdClass();
        $message->params->phoneMac = 'AAAAAAAAAAAA';
        $message->params->register = false;
        $message->params->phoneIp = $this->local_ip;
        $message->params->id = 1;
        
        $results = $this->send_udp($message);

        foreach($results as $data) 
        {
            $mac = $data['result']['mac'];
            $from = $data['from'];
            if($mac)
            {
                // push mac / ip object to $results
                array_push($bulbs, ['mac' => $mac, 'ip' => $from]);
                $this->get_pilot_state($from);
                $this->get_user_config($from);
                $this->get_system_config($from);
                break;
            }
        }

        return $bulbs;
    }

    public function get_user_config($ip) : array
    {
        $message = new \stdClass();
        $message->method = 'getUserConfig';
        $message->params = new \stdClass();
        $results = $this->send_udp($message, $ip);
        //\Log::info('getUserConfig results: ' . print_r($results, true));
        return $results;
    }

    public function get_system_config($ip) : array
    {
        $message = new \stdClass();
        $message->method = 'getSystemConfig';
        $message->params = new \stdClass();
        $results = $this->send_udp($message, $ip);
        //\Log::info('getSystemConfig results: ' . print_r($results, true));

        $data = $results[0]['result'];
        $bulb = Bulb::where('mac', $data['mac'])->first();
        if(!$bulb) return [];

        $update = false;
        if($bulb->model != $data['moduleName'])
        {
            $bulb->model = $data['moduleName'];
            $update = true;
        }

        if($update) $bulb->save();

        return $results;
    }

    public function get_pilot_state($ip) : array
    {
        $pilot = new \stdClass();
        $pilot->method = 'getPilot';
        $pilot->params = new \stdClass();
        
        $results = $this->send_udp($pilot, $ip);
        foreach($results as $result)
        {
            //\Log::info('getPilot result: ' . print_r($result, true));
            $bulb = $result['result'];
            $b = Bulb::where('mac', $bulb['mac'])->first();
            if($b)
            {
                $update = false;
                // check if bulb state has changed
                if($b->state != $bulb['state']) $update = true;
                if(isset($bulb['dimming']) && $b->dimming != $bulb['dimming']) $update = true;

                if(!isset($bulb['r']))
                {
                    $bulb['r'] = 0;
                }
                
                if(!isset($bulb['g'])) 
                {
                    $bulb['g'] = 0;
                }
                
                if(!isset($bulb['b']))
                {
                    $bulb['b'] = 0;
                }

                if($b->red != $bulb['r'])
                {
                    $b->red = $bulb['r'];
                    $update = true;
                }
                
                if($b->green != $bulb['g'])
                {
                    $b->green = $bulb['g'];
                    $update = true;
                }

                if($b->blue != $bulb['b'])
                {
                    $b->blue = $bulb['b'];
                    $update = true;
                }

                if(isset($bulb['temperature']) && $b->temperature != $bulb['temperature']) $update = true;
                //if($b->signal != $bulb['rssi']) $update = true;
                if(!$update) continue;

                //\Log::info('Updating bulb: ' . print_r($bulb, true));

                $b->state = $bulb['state'];
                if(isset($bulb['dimming'])) $b->dimming = $bulb['dimming'];
                $b->signal = $bulb['rssi'];
                $b->save();
            }
        }
        return $results;
    }

    public function set_pilot_state($ip, RGBColor $color, int $dimming, int $temp, bool $state): array
    {
        $results = [];
        $message = null;
        $stateStr = $state ? 'on' : 'off';

        $message = "{}";

        if(!$temp)
        {
            [$r, $g, $b] = $color->getValue();
        
            $message = sprintf(
                '{"method":"setPilot","params":{"r":%d,"g":%d,"b":%d,"dimming":%d,"state":%d}}',
                $r,
                $g,
                $b,
                $dimming,
                $state
            );
        }
        else
        {
            $message = sprintf(
                '{"method":"setPilot","params":{"r":%d,"g":%d,"b":%d,"dimming":%d,"temp":%d,"state":%d}}',
                "0",
                "0",
                "0",
                $dimming,
                $temp,
                $state
            );
        }

        $results = $this->send_udp(json_decode($message), $ip);
        return $results;
    }

    public function send_udp($message, $ip = null) : array
    {
        $ip = $ip ? $ip : $this->broadcast_address;
        $results = [];

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);;
        $m = json_encode($message);
        
        socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
        socket_sendto($socket, $m, strlen($m), 0, $ip, 38899);

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 1, 'usec' => 0));

        $start_time = microtime(true);
        while (true) {
            $buf = '';
            $from = '';
            $port = 0;
            $bytes = @socket_recvfrom($socket, $buf, 1024, 0, $from, $port);
            if ($bytes === false) {
                break;
            }
            if ($bytes > 0) {
                $data = json_decode($buf, true);
                $data['from'] = $from;
                array_push($results, $data);
            }
            if (microtime(true) - $start_time > $this->wait_time) {
                break;
            }
        }

        socket_close($socket);
        return $results;
    }

    public function get_local_ip()
    {
        $base_url = config('app.url');
        // remove protocol and port from url
        $hostname = parse_url($base_url, PHP_URL_HOST);
        $ip = gethostbyname($hostname);
        return $ip;
    }
}
