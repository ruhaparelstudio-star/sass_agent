<?php

return [
    'internal_secret' => env('WA_INTERNAL_SECRET'),
    'gateway_outbound_url' => env('WA_GATEWAY_OUTBOUND_URL'),
    'gateway_qr_url' => env('WA_GATEWAY_QR_URL'),
    'gateway_connect_url' => env('WA_GATEWAY_CONNECT_URL'),
    'gateway_disconnect_url' => env('WA_GATEWAY_DISCONNECT_URL'),
    'gateway_timeout_seconds' => (int) env('WA_GATEWAY_TIMEOUT_SECONDS', 10),
    'inbound_rate_limit' => [
        'max_attempts' => (int) env('WA_INBOUND_RATE_LIMIT_MAX_ATTEMPTS', 30),
        'decay_seconds' => (int) env('WA_INBOUND_RATE_LIMIT_DECAY_SECONDS', 60),
    ],
];
