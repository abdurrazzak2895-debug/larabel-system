<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SVP / Takamol API
    |--------------------------------------------------------------------------
    */

    'base_url'      => env('SVP_BASE_URL', 'https://svp-international-api.pacc.sa'),
    'base_url_host' => env('SVP_BASE_URL_HOST', 'svp-international-api.pacc.sa'),
    'timeout'       => (int) env('SVP_TIMEOUT', 30),
    'retry_times'   => (int) env('SVP_RETRY_TIMES', 3),
    'retry_delay'   => (int) env('SVP_RETRY_DELAY', 1000),
    'tenant_name'   => env('SVP_TENANT_NAME', 'svp-international'),
    'country_id'    => (int) env('SVP_COUNTRY_ID', 78),
    // SVP expects a Prometric language code (for example LOABB), not an ISO code such as en.
    'default_language_code' => env('SVP_DEFAULT_LANGUAGE_CODE', 'LOABB'),
    'default_methodology'   => env('SVP_DEFAULT_METHODOLOGY', 'in_person'),
    'log_requests'  => (bool) env('SVP_LOG_REQUESTS', true),
    'log_channel'   => env('SVP_LOG_CHANNEL', 'daily'),

];
