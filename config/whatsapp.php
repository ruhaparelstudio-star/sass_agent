<?php

return [
    'internal_secret' => env('WA_INTERNAL_SECRET'),
    'gateway_outbound_url' => env('WA_GATEWAY_OUTBOUND_URL'),
    'gateway_qr_url' => env('WA_GATEWAY_QR_URL'),
    'gateway_timeout_seconds' => (int) env('WA_GATEWAY_TIMEOUT_SECONDS', 10),
];
