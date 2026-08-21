<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SVP / Takamol API
    |--------------------------------------------------------------------------
    */

    'base_url'      => env('SVP_BASE_URL', 'https://svp-international-api.pacc.sa'),
    'base_url_host' => env('SVP_BASE_URL_HOST', 'svp-international-api.pacc.sa'),
    'web_base_url'  => env('SVP_WEB_BASE_URL', 'https://svp-international'.'.pacc.sa'),
    'hyperpay_redirect_url' => env('SVP_HYPERPAY_REDIRECT_URL', 'https://eu-prod.oppwa.com/v1/redirect.html'),
    'hyperpay_widget_url' => env('SVP_HYPERPAY_WIDGET_URL', 'https://eu-prod.oppwa.com/v1/paymentWidgets.js'),
    'timeout'       => (int) env('SVP_TIMEOUT', 30),
    'retry_times'   => (int) env('SVP_RETRY_TIMES', 3),
    'retry_delay'   => (int) env('SVP_RETRY_DELAY', 1000),
    // Read-only availability calls must fail fast; booking/reservation calls keep
    // the general SVP timeout and retry policy.
    'availability_timeout' => (int) env('SVP_AVAILABILITY_TIMEOUT', 5),
    'availability_connect_timeout' => (int) env('SVP_AVAILABILITY_CONNECT_TIMEOUT', 2),
    'availability_account_attempts' => (int) env('SVP_AVAILABILITY_ACCOUNT_ATTEMPTS', 3),
    'availability_cache_ttl' => (int) env('SVP_AVAILABILITY_CACHE_TTL', 60),
    'availability_city_cache_ttl' => (int) env('SVP_AVAILABILITY_CITY_CACHE_TTL', 900),
    'tenant_name'   => env('SVP_TENANT_NAME', 'svp-international'),
    'country_id'    => (int) env('SVP_COUNTRY_ID', 78),
    // SVP expects a Prometric language code (for example LOABB), not an ISO code such as en.
    'default_language_code' => env('SVP_DEFAULT_LANGUAGE_CODE', 'LOABB'),
    'languages' => [
        [
            'id' => 392,
            'arabic_name' => 'بنغالي',
            'code' => 'LOABB',
            'english_name' => 'Bengali',
            'exam_engine_id' => 1,
            'exam_engine_name' => 'prometric',
            'language_code' => 'bn',
            'non_targeted' => false,
            'question_count' => 15,
        ],
    ],
    'default_methodology'   => env('SVP_DEFAULT_METHODOLOGY', 'in_person'),
    // Some SVP deployments omit earlier dates from available_dates even though
    // the date-specific exam_sessions endpoint still returns valid seats.
    'session_date_probe_backfill_days' => (int) env('SVP_SESSION_DATE_PROBE_BACKFILL_DAYS', 7),
    // Portal service fee charged to the Agency wallet per successful booking
    // when no admin booking_price setting exists. This is separate from SVP's
    // own reservation amount or reservation-credit decision.
    'portal_booking_fee'     => (float) env('SVP_PORTAL_BOOKING_FEE', 0),
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
    // Availability uses backend-managed accounts by default. Enable only during migration.
    'allow_session_availability_fallback' => (bool) env('SVP_ALLOW_SESSION_AVAILABILITY_FALLBACK', false),
    'log_requests'  => (bool) env('SVP_LOG_REQUESTS', true),
    'log_channel'   => env('SVP_LOG_CHANNEL', 'daily'),

];
