<?php

/**
 * Print the shape of the SVP login + OTP verification envelopes with long
 * values masked, to find where the bearer token actually lives.
 */

use App\Services\SvpApiService;
use App\Services\SvpOtp\OtpAutoVerifier;

$say = static fn (string $line = ''): string => '  '.$line.PHP_EOL;

$mask = static function (mixed $value, string $key = '') use (&$mask): mixed {
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = $mask($v, (string) $k);
        }

        return $out;
    }
    if (! is_string($value)) {
        return $value;
    }
    if (strlen($value) > 40) {
        return '<string len='.strlen($value).' prefix='.substr($value, 0, 6).'>';
    }

    return $value;
};

$email = trim((string) config('svp.auto_login.email'));
$password = (string) config('svp.auto_login.password');
$otp = app(OtpAutoVerifier::class);
$api = app(SvpApiService::class);

$started = time() - 60;
$login = $api->login($email, $password, 'email');
echo $say('login status='.$login['status']);
echo $say('login body = '.substr((string) json_encode($mask($login['body'])), 0, 400));

$code = $otp->awaitCode($started);
echo $say('otp code found = '.($code === null ? 'no' : 'yes (source '.$code['source'].')'));
if ($code === null) {
    return;
}

$verify = $api->verifyOtp($email, $password, $code['code'], 'email');
echo $say('verify status='.$verify['status']);
echo $say('verify body = '.substr((string) json_encode($mask($verify['body'])), 0, 900));
