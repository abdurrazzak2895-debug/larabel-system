<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SVP / Takamol API
    |--------------------------------------------------------------------------
    */

    'base_url'      => env('SVP_BASE_URL', 'https://svp-international-api.pacc.sa'),
    'base_url_host' => env('SVP_BASE_URL_HOST', 'svp-international-api.pacc.sa'),
    'web_base_url'  => env('SVP_WEB_BASE_URL', 'https://svp-international.pacc.sa'),
    'hyperpay_redirect_url' => env('SVP_HYPERPAY_REDIRECT_URL', 'https://eu-prod.oppwa.com/v1/redirect.html'),
    'timeout'       => (int) env('SVP_TIMEOUT', 30),
    'retry_times'   => (int) env('SVP_RETRY_TIMES', 3),
    'retry_delay'   => (int) env('SVP_RETRY_DELAY', 1000),
    'tenant_name'   => env('SVP_TENANT_NAME', 'svp-international'),
    'country_id'    => (int) env('SVP_COUNTRY_ID', 78),
    // SVP expects a Prometric language code (for example LOABB), not an ISO code such as en.
    'default_language_code' => env('SVP_DEFAULT_LANGUAGE_CODE', 'LOABB'),
    'default_methodology'   => env('SVP_DEFAULT_METHODOLOGY', 'in_person'),
    // SVP's category-filtered center response can omit known Dhaka centers.
    // Keep the supplied real SVP IDs canonical so both booking panels expose
    // the complete seven-center set; session availability is still verified
    // against the selected center before a hold is created.
    'dhaka_test_centers' => [
        ['id' => '403', 'name' => 'Arkan Al-Taameer for professional classification - Dhaka', 'city' => 'Dhaka', 'country_code' => 'BD'],
        ['id' => '223', 'name' => 'Manikganj Technical Training Center', 'city' => 'Dhaka', 'country_code' => 'BD'],
        ['id' => '220', 'name' => 'Kishoreganj Technical Training Centre', 'city' => 'Dhaka', 'country_code' => 'BD'],
        ['id' => '218', 'name' => 'Narsingdi Technical Training Center', 'city' => 'Dhaka', 'country_code' => 'BD'],
        ['id' => '102', 'name' => 'Tangail Technical Training Center', 'city' => 'Dhaka', 'country_code' => 'BD'],
        ['id' => '45', 'name' => 'Bangladesh German TTC', 'city' => 'Dhaka', 'country_code' => 'BD'],
        ['id' => '17', 'name' => 'Bangladesh Korea TTC Dhaka', 'city' => 'Dhaka', 'country_code' => 'BD'],
    ],
    'log_requests'  => (bool) env('SVP_LOG_REQUESTS', true),
    'log_channel'   => env('SVP_LOG_CHANNEL', 'daily'),

];
