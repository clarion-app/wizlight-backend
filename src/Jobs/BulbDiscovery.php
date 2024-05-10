<?php

namespace ClarionApp\WizlightBackend\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BulbDiscovery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $broadcast_address = "255.255.255.255";
        $wait_time = 15.0;
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        $phone_ip = long2ip(mt_rand());
        $message = new \stdClass();
        $message->method = 'registration';
        $message->params = new \stdClass();
        $message->params->phoneMac = $this->generate_random_mac();
        $message->params->register = false;
        $message->params->phoneIp = $phone_ip;
        $message->params->id = mt_rand();
        $message = json_encode($message);
        print $message."\n";
        socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
        socket_sendto($socket, $message, strlen($message), 0, $broadcast_address, 38899);

        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 5, 'usec' => 0));

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
                echo "Received data from $from: $buf\n";
                $data = json_decode($buf, true);
                $mac = $data['result']['mac'];
                if ($mac) {
                    \Log::info("Discovered bulb with MAC $mac at IP $from");
                }
            }

            if (microtime(true) - $start_time >= $wait_time) {
                break;
            }
        }

        socket_close($socket);
    }

    private function generate_random_mac() {
        $mac = "";
        for ($i = 0; $i < 12; $i++) {
            $mac .= dechex(mt_rand(0, 15));
        }
        return $mac;
    }
}
