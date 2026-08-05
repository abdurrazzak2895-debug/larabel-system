<?php

use App\Services\Providers\TakamolProvider;

$token = 'eyJhbGciOiJIUzI1NiJ9.eyJleHAiOjE3ODU4ODE0NDMsInVzZXJfaWQiOjEwMjU5NzksImF1dGhfcHJvdmlkZXIiOiJsb2NhbCIsInVpZCI6IjZjZGVjNWZmLTIwNTQtNDdiNC1hM2EyLTIxMTY2MGViNmM0MSIsInJ1aWQiOiI5Y2MwMzQyMC0wNWY2LTRhZGQtYjVjMS1iY2M2MDA1NTU3MjAifQ.H1CGCSUJ69_juHIVg9hHYPxuQhtEKln-URZVtUMaVs0';

// Real-system header set (Postman): NO X-CSRF-Token, NO Origin, NO Referer.
$c = app(TakamolProvider::class)->withToken($token);

$endpoints = [
    'permissions'     => fn () => $c->permissions(),
    'profile'         => fn () => $c->profile(),
    'certificate'     => fn () => $c->certificatePrice(),
    'exam_sessions'   => fn () => $c->examSessions(),
    'available_dates' => fn () => $c->availableDates(),
    'occupations'     => fn () => $c->occupations(),
    'constraints'     => fn () => $c->examConstraints(),
    'reservations'    => fn () => $c->reservationDetails(),
    'validate_res'    => fn () => $c->validateReservation(),
    'validate_pay'    => fn () => $c->validatePendingPayment(),
    'notifications'   => fn () => $c->notifications(),
    'verifications'   => fn () => $c->verificationRequests(),
];

foreach ($endpoints as $name => $fn) {
    $r = $fn();
    echo str_pad($name, 16).' => '.$r->status().' | '.substr($r->getContent(), 0, 150).PHP_EOL;
}
