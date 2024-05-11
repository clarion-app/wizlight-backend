<?php

namespace ClarionApp\WizlightBackend;


class Wiz
{
    private $broadcast_address;
    private $wait_time;

    public function __construct($wait_time = 30.0, $broadcast_address = "255.255.255.255")
    {
        $this->broadcast_address = $broadcast_address;
        $this->wait_time = $wait_time;
    }

    public function discover() : array
    {
        $results = [];

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);;
        $message = new \stdClass();
        $message->method = 'registration';
        $message->params = new \stdClass();
        $message->params->phoneMac = 'AAAAAAAAAAAA';
        $message->params->register = false;
        $message->params->phoneIp = '1.2.3.4';
        $message->params->id = 1;
        $message = json_encode($message);
        socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
        socket_sendto($socket, $message, strlen($message), 0, $this->broadcast_address, 38899);

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
                $mac = $data['result']['mac'];
                if ($mac) {
                    // push mac / ip object to $results
                    array_push($results, ['mac' => $mac, 'ip' => $from]);
                    \Log::info("Discovered bulb with MAC $mac at IP $from");
                    break;
                }
            }
            if (microtime(true) - $start_time > $this->wait_time) {
                break;
            }
        }

        socket_close($socket);
        return $results;
    }
}