<?php

return [
    'base_url' => env('PORTAL_AVAILABILITY_BASE_URL', 'https://svp-international.xyz'),
    'timeout' => (int) env('PORTAL_AVAILABILITY_TIMEOUT', 15),
    'connect_timeout' => (int) env('PORTAL_AVAILABILITY_CONNECT_TIMEOUT', 5),
    'cache_ttl' => (int) env('PORTAL_AVAILABILITY_CACHE_TTL', 30),
    'credential_cache_ttl' => (int) env('PORTAL_AVAILABILITY_CREDENTIAL_CACHE_TTL', 60),
    'rate_limit_delay_ms' => (int) env('PORTAL_AVAILABILITY_DELAY_MS', 250),
];
