<?php

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;

$base = 'https://svp-international-api.pacc.sa';
$jar  = new CookieJar();

// Login + OTP using same user as Postman token (833921)
$loginBody = Http::baseUrl($base)
    ->withOptions(['cookies' => $jar])
    ->timeout(30)
    ->acceptJson()
    ->asJson()
    ->post('/api/v1/sessions/login', [
        'user' => [
            'login'    => 'ikramhossainsvp853@yopmail.com',
            'password' => 'aRrazzak90#',
            'otp_method' => 'email',
            'fe_app'    => 'legislator',
        ],
    ]);

echo 'LOGIN  => '.$loginBody->status().' | '.substr($loginBody->body(), 0, 200).PHP_EOL;
echo 'COOKIES=> '.count($jar->toArray()).' stored'.PHP_EOL;

// Now GET an authenticated endpoint with Bearer token + SAME cookie jar
$token = 'eyJhbGciOiJIUzI1NiJ9.eyJleHAiOjE3ODU4NDc4OTMsInVzZXJfaWQiOjgzMzkyMSwiYXV0aF9wcm92aWRlciI6ImxvY2FsIiwidWlkIjoiMDc2MWZhOGUtYmY0Yi00ZTVmLTljZjQtMmQxMjNhZTM3NmZjIiwicnVpZCI6IjQwMmJhMDcyLWJlMjctNGRlYS1hZmVhLTU4YjFhN2ZhNDhmYyJ9.ZXQ2UryAYPfTdyFE_RrudwbhHoqR3JFdtf14BSAIno8';

$profile = Http::baseUrl($base)
    ->withOptions(['cookies' => $jar])
    ->timeout(30)
    ->acceptJson()
    ->withToken($token)
    ->withHeader('Origin', 'https://svp-international.pacc.sa')
    ->withHeader('Referer', 'https://svp-international.pacc.sa/')
    ->get('/api/v1/individual_labor_space/profile?locale=en');

echo 'PROFILE => '.$profile->status().' | '.substr($profile->body(), 0, 300).PHP_EOL;
