<?php

return [
    'base_url' => env('PORTAL_AVAILABILITY_BASE_URL', 'https://svp-international.xyz'),
    'timeout' => (int) env('PORTAL_AVAILABILITY_TIMEOUT', 15),
    'connect_timeout' => (int) env('PORTAL_AVAILABILITY_CONNECT_TIMEOUT', 5),
    'cache_ttl' => (int) env('PORTAL_AVAILABILITY_CACHE_TTL', 30),
    'empty_retry_attempts' => (int) env('PORTAL_AVAILABILITY_EMPTY_RETRY_ATTEMPTS', 1),
    'empty_retry_delay_ms' => (int) env('PORTAL_AVAILABILITY_EMPTY_RETRY_DELAY_MS', 250),
    'empty_retry_max_delay_ms' => (int) env('PORTAL_AVAILABILITY_EMPTY_RETRY_MAX_DELAY_MS', 2000),
    'recovery_probe_timeout' => (int) env('PORTAL_AVAILABILITY_RECOVERY_PROBE_TIMEOUT', 15),
    'recovery_failure_threshold' => (int) env('PORTAL_AVAILABILITY_RECOVERY_FAILURE_THRESHOLD', 3),
    'recovery_circuit_minutes' => (int) env('PORTAL_AVAILABILITY_RECOVERY_CIRCUIT_MINUTES', 5),
    'recovery_retry_attempts' => (int) env('PORTAL_AVAILABILITY_RECOVERY_RETRY_ATTEMPTS', 2),
    'recovery_retry_delay_ms' => (int) env('PORTAL_AVAILABILITY_RECOVERY_RETRY_DELAY_MS', 500),
    'recovery_retry_max_delay_ms' => (int) env('PORTAL_AVAILABILITY_RECOVERY_RETRY_MAX_DELAY_MS', 5000),
    'recovery_refresh_cooldown_minutes' => (int) env('PORTAL_AVAILABILITY_RECOVERY_REFRESH_COOLDOWN_MINUTES', 10),
    'credential_cache_ttl' => (int) env('PORTAL_AVAILABILITY_CREDENTIAL_CACHE_TTL', 60),
    'refresh_path' => env('PORTAL_AVAILABILITY_REFRESH_PATH', '/api/accounts/%s/refresh'),
    'refresh_interval_minutes' => (int) env('PORTAL_AVAILABILITY_REFRESH_INTERVAL_MINUTES', 10),
    'rate_limit_delay_ms' => (int) env('PORTAL_AVAILABILITY_DELAY_MS', 250),
    'external_api' => [
        'header' => 'X-Portal-API-Key',
        'rate_limit_per_minute' => (int) env('PORTAL_AVAILABILITY_EXTERNAL_RATE_LIMIT', 60),
    ],
];
