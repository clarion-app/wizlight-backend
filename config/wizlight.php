<?php

return [
    'broadcast_address' => env('WIZLIGHT_BROADCAST_ADDRESS', '255.255.255.255'),
    'udp_port' => env('WIZLIGHT_UDP_PORT', 38899),
    'phone_mac' => env('WIZLIGHT_PHONE_MAC', 'AAAAAAAAAAAA'),
    'udp_wait_time' => env('WIZLIGHT_UDP_WAIT_TIME', 30.0),

    'ownership' => [
        /*
         * FR-009. A claim on a bulb lapses when the owning node's
         * `local_node_seen_at` is older than this. Four orders of magnitude
         * larger than the discovery interval, which is what makes reclaim
         * thrashing between two healthy nodes impossible.
         */
        'lapse_hours' => (int) env('WIZLIGHT_OWNERSHIP_LAPSE_HOURS', 24),

        /*
         * How stale the owner's own claim must be before discovery refreshes
         * `local_node_seen_at`. FR-009 wants the timestamp advancing; the
         * no-change rule (SC-008b) wants an unchanged bulb to cost zero writes,
         * and every write here is replicated on-chain. Refreshing hourly rather
         * than every discovery cycle satisfies both: a healthy owner still
         * renews its claim 24 times per lapse window.
         */
        'heartbeat_minutes' => (int) env('WIZLIGHT_OWNERSHIP_HEARTBEAT_MINUTES', 60),
    ],

    'throttle' => [
        /*
         * FR-002b. Minimum gap between consecutive sends to *one* device
         * (~5/s at the default, under the "few per second" hardware ceiling).
         * Per-device, never global: a room update fanning out to N lights must
         * not serialize behind a shared pace.
         */
        'min_interval_ms' => (int) env('WIZLIGHT_THROTTLE_MIN_INTERVAL_MS', 200),
    ],

    'capability' => [
        /*
         * Fallback minimum brightness percentage for bulbs whose
         * `min_brightness_pct` column is still NULL (not yet probed).
         * Most Wiz devices support 1 %, but some older firmware floors
         * at higher values; discovery will overwrite this with the real
         * floor once it runs.
         */
        'default_min_brightness_pct' => (int) env('WIZLIGHT_CAPABILITY_DEFAULT_MIN_BRIGHTNESS_PCT', 1),
    ],
];
