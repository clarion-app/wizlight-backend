<?php

return [
    'broadcast_address' => env('WIZLIGHT_BROADCAST_ADDRESS', '255.255.255.255'),
    'udp_port' => env('WIZLIGHT_UDP_PORT', 38899),
    'phone_mac' => env('WIZLIGHT_PHONE_MAC', 'AAAAAAAAAAAA'),
    'udp_wait_time' => env('WIZLIGHT_UDP_WAIT_TIME', 30.0),
];
