<?php

return [
    'header' => env('TAKAMOL_EXTERNAL_API_KEY_HEADER', 'X-Takamol-API-Key'),
    'rate_limit_per_minute' => (int) env('TAKAMOL_EXTERNAL_API_RATE_LIMIT', 60),
];
