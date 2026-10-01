<?php

return [
    /*
    |--------------------------------------------------------------------------
    | T2Hub (Takamol agent portal) integration
    |--------------------------------------------------------------------------
    |
    | The live booking catalogue — occupations, cities, available dates, test
    | centres and exam sessions — is served by T2Hub's agent API. The portal is
    | a Livewire application, so the client logs in with an agent account,
    | keeps the resulting session cookie plus the page's AES key (`window.__sk`)
    | and decrypts the encrypted JSON responses it returns.
    |
    | T2HUB_EMAIL / T2HUB_PASSWORD are the agent credentials. T2HUB_SESSION_KEY
    | and T2HUB_SESSION_COOKIE are optional pre-captured values that are used
    | when the portal only publishes its key to browser JavaScript.
    |
    */

    'base_url' => rtrim((string) env('T2HUB_BASE_URL', 'https://takamol.t2hub.app'), '/'),
    'app_path' => '/' . trim((string) env('T2HUB_APP_PATH', 'takamol'), '/'),
    'login_url' => env('T2HUB_LOGIN_URL', 'https://takamol.t2hub.app/takamol/agent/login'),

    'email' => env('T2HUB_EMAIL'),
    'password' => env('T2HUB_PASSWORD'),

    // Optional pre-captured session material (see docs/t2hub-integration.md).
    'session_key' => env('T2HUB_SESSION_KEY'),
    'session_cookie' => env('T2HUB_SESSION_COOKIE'),
    'session_csrf' => env('T2HUB_SESSION_CSRF'),

    // Upstream PACC search can take longer than 20s with auto_fix_search on.
    'timeout' => (int) env('T2HUB_FETCH_TIMEOUT_MS', 25000) / 1000,
    'connect_timeout' => (int) env('T2HUB_CONNECT_TIMEOUT', 10),

    // Where the encrypted session snapshot is kept between requests.
    'session_store' => env('T2HUB_SESSION_STORE', storage_path('app/t2hub/session.json')),
    'session_ttl_minutes' => (int) env('T2HUB_SESSION_TTL_MINUTES', 30),

    // Re-login automatically when the stored session is missing or rejected.
    'auto_login' => filter_var(env('T2HUB_AUTO_LOGIN', true), FILTER_VALIDATE_BOOL),

    // Which upstream the booking chain reads from: t2hub | svp.
    'data_source' => env('BOOKING_DATA_SOURCE', 't2hub'),
];
