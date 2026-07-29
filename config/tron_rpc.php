<?php

return [
    'endpoints' => array_values(array_filter(array_map('trim', explode(',', (string) env('TRON_RPC_ENDPOINTS', ''))))),
    'app_id' => env('TRON_RPC_APP_ID', 'robots'),
    'secret' => env('TRON_RPC_SECRET'),
    'connect_timeout_seconds' => max(0.1, (float) env('TRON_RPC_CONNECT_TIMEOUT', 1)),
    'request_timeout_seconds' => max(0.5, (float) env('TRON_RPC_REQUEST_TIMEOUT', 3)),
    'debug_log' => (bool) env('TRON_RPC_DEBUG_LOG', false),
];
