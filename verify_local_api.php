<?php

/**
 * End-to-end verification:
 * OUR Laravel app (localhost:8000/api/v1/*) → SVP API (real system)
 */

$base = 'http://localhost:8000/api/v1';
$token = 'eyJhbGciOiJIUzI1NiJ9.eyJleHAiOjE3ODU4ODE0NDMsInVzZXJfaWQiOjEwMjU5NzksImF1dGhfcHJvdmlkZXIiOiJsb2NhbCIsInVpZCI6IjZjZGVjNWZmLTIwNTQtNDdiNC1hM2EyLTIxMTY2MGViNmM0MSIsInJ1aWQiOiI5Y2MwMzQyMC0wNWY2LTRhZGQtYjVjMS1iY2M2MDA1NTU3MjAifQ.H1CGCSUJ69_juHIVg9hHYPxuQhtEKln-URZVtUMaVs0';

$endpoints = [
    'permissions'      => '/individual_labor_space/permissions',
    'profile'          => '/individual_labor_space/profile',
    'certificate_price'=> '/individual_labor_space/certificate_price',
    'exam_sessions'    => '/individual_labor_space/exam_sessions',
    'available_dates'  => '/individual_labor_space/exam_sessions/available_dates',
    'occupations'      => '/individual_labor_space/occupations',
    'exam_constraints' => '/individual_labor_space/exam_constraints',
    'reservations'     => '/individual_labor_space/exam_reservations',
    'validate_res'     => '/individual_labor_space/exam_reservations/validate',
    'validate_pay'     => '/individual_labor_space/payments/validate_pending',
    'notifications'    => '/individual_labor_space/notifications',
    'verifications'    => '/individual_labor_space/verification_requests',
];

$headers = [
    'Authorization: Bearer '.$token,
    'Accept: application/json, text/plain, */*',
    'X-Tenant-Name: svp-international',
    'common: [object Object]',
    'Cache-Control: no-cache',
    'Pragma: no-cache',
    'sec-ch-ua: "Not;A=Brand";v="8", "Chromium";v="150", "Google Chrome";v="150"',
    'sec-ch-ua-mobile: ?0',
    'sec-ch-ua-platform: "Windows"',
    'Sec-Fetch-Dest: empty',
    'Sec-Fetch-Mode: cors',
    'Sec-Fetch-Site: same-site',
];

foreach ($endpoints as $name => $path) {
    $ch = curl_init($base.$path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $preview = $err ? "CURL-ERR: {$err}" : substr((string) $body, 0, 100);
    echo str_pad($name, 17)." => {$status} | {$preview}".PHP_EOL;
}
