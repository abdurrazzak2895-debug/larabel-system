<?php

use App\Services\Providers\TakamolProvider;

$token = 'eyJhbGciOiJIUzI1NiJ9.eyJleHAiOjE3ODU4NDc4OTMsInVzZXJfaWQiOjgzMzkyMSwiYXV0aF9wcm92aWRlciI6ImxvY2FsIiwidWlkIjoiMDc2MWZhOGUtYmY0Yi00ZTVmLTljZjQtMmQxMjNhZTM3NmZjIiwicnVpZCI6IjQwMmJhMDcyLWJlMjctNGRlYS1hZmVhLTU4YjFhN2ZhNDhmYyJ9.ZXQ2UryAYPfTdyFE_RrudwbhHoqR3JFdtf14BSAIno8';

$provider = app(TakamolProvider::class)->withToken($token);

$tests = [
    'profile'        => fn () => $provider->profile(),
    'permissions'    => fn () => $provider->permissions(),
    'occupations'    => fn () => $provider->occupations(),
    'certificate'    => fn () => $provider->certificatePrice(),
    'exam_sessions'  => fn () => $provider->examSessions(),
    'reservations'   => fn () => $provider->reservationDetails(),
    'constraints'    => fn () => $provider->examConstraints(),
    'notifications'  => fn () => $provider->notifications(),
    'verify_requests'=> fn () => $provider->verificationRequests(),
];

foreach ($tests as $name => $fn) {
    try {
        $res = $fn();
        $body = $res instanceof \Illuminate\Http\JsonResponse
            ? $res->getContent()
            : json_encode($res);
        $code = method_exists($res, 'status') ? $res->status() : 0;
        echo str_pad($name, 15).' => '.$code.' | '.substr($body ?? '', 0, 180).PHP_EOL;
    } catch (\Throwable $e) {
        echo str_pad($name, 15).' => EX: '.$e->getMessage().PHP_EOL;
    }
}
