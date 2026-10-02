<?php

echo '  hello from probe'.PHP_EOL;

try {
    $resolver = app(App\Services\Svp\SvpOccupationResolver::class);
    echo '  resolver class: '.get_class($resolver).PHP_EOL;
} catch (\Throwable $e) {
    echo '  resolver failed: '.$e->getMessage().PHP_EOL;
}

try {
    $booking = app(App\Services\BookingService::class);
    echo '  BookingService ok: '.get_class($booking).PHP_EOL;
} catch (\Throwable $e) {
    echo '  BookingService failed: '.$e->getMessage().PHP_EOL;
}
