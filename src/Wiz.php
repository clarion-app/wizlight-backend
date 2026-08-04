<?php
namespace ClarionApp\WizlightBackend;

use ClarionApp\WizlightBackend\LightColor;
use ClarionApp\WizlightBackend\Models\Bulb;
use ClarionApp\WizlightBackend\Transport\SocketUdpTransport;
use ClarionApp\WizlightBackend\Transport\UdpTransport;

class Wiz
{
    private string $broadcastAddress;
    private float $waitTime;
    private string $localIp;
    private string $phoneMac;
    private int $udpPort;
    private UdpTransport $transport;

    public function __construct(
        ?float $wait_time = null,
        ?string $broadcast_address = null,
        ?UdpTransport $transport = null
    ) {
        $this->broadcastAddress = $broadcast_address ?? config('wizlight.broadcast_address', '255.255.255.255');
        $this->waitTime = $wait_time ?? config('wizlight.udp_wait_time', 30.0);
        $this->phoneMac = config('wizlight.phone_mac', 'AAAAAAAAAAAA');
        $this->udpPort = config('wizlight.udp_port', 38899);
        $this->localIp = $this->get_local_ip();
        $this->transport = $transport ?? new SocketUdpTransport($this->broadcastAddress, $this->udpPort);
    }

    public function discover(): array
    {
        $bulbs = [];

        $message = new \stdClass();
        $message->method = 'registration';
        $message->params = new \stdClass();
        $message->params->phoneMac = $this->phoneMac;
        $message->params->register = false;
        $message->params->phoneIp = $this->localIp;
        $message->params->id = 1;

        $results = $this->send_udp($message);

        foreach ($results as $data) {
            $mac = $data['result']['mac'];
            $from = $data['from'];
            if ($mac) {
                array_push($bulbs, ['mac' => $mac, 'ip' => $from]);
                $this->get_pilot_state($from);
                $this->get_user_config($from);
                $this->get_system_config($from);
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
        if(!$results) return [];
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

    public function set_pilot_state($ips, RGBColor $color, int $dimming, int $temp, bool $state): array
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
                0,
                0,
                0,
                $dimming,
                $temp,
                $state
            );
        }

        $results = $this->send_udp(json_decode($message), $ips);
        return $results;
    }

    public function send_udp($message, $ips = null): array
    {
        $targets = $ips
            ? (is_array($ips) ? $ips : [$ips])
            : [$this->broadcastAddress];

        $this->transport->send($message, $targets);
        $results = $this->transport->receive($this->waitTime);
        $this->transport->close();

        return $results ?: [];
    }

    public function get_local_ip()
    {
        $base_url = config('app.url');
        // remove protocol and port from url
        $hostname = parse_url($base_url, PHP_URL_HOST);
        $ip = gethostbyname($hostname);
        // \Log::info("Local IP address: " . $ip);
        return $ip;
    }
}
