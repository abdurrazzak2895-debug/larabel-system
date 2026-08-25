<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Portal wallet deposit methods
    |--------------------------------------------------------------------------
    |
    | These methods are for manual wallet deposits only. SVP booking/card
    | payment remains configured independently in config/svp.php.
    |
    */
    'portal_deposit_methods' => ['bkash', 'nagad'],

    'merchant_numbers' => [
        'bkash' => env('BKASH_MERCHANT_NUMBER', ''),
        'nagad' => env('NAGAD_MERCHANT_NUMBER', ''),
    ],
];
