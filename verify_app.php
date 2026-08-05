<?php

$url = 'http://localhost:8000/';

$cookies = [
    'NEXT_LOCALE=en',
    'auth_token=eyJhbGciOiJIUzI1NiJ9.eyJhdXRoZW50aWNhdGVkIjp0cnVlLCJleHAiOjE3ODgyODc0MTR9.lvcTiZiTV95L-lYPTj213VgEJ_7ONT98AK00iv-SfEQ',
    'XSRF-TOKEN=eyJpdiI6ImMvcENiWFk5TE1oRGdDODhHeWlOYnc9PSIsInZhbHVlIjoid1kvU3dVWFJkK1oxV0NKVWVJZE04eXV2Y29McU5FRHlBSldoSmVUditLVUUyTFlJM1QrQkR6dDcvb0xEOTJtUlNuTmtRaFpiYStJWDNhd2RkUE1vWEZaaDNJTzRMVFY1RlVBam9KaHpCTGlqZU8xVVlXaWJmeTM2MGpvVkk2d24iLCJtYWMiOiI4NzE1NTEzYjc2NDQ3NzFjMGM4M2YxN2VhOGRiMWM2ZWNjYTU4Njg1NWE5ZWQ2NWZmNjFkYzY1NjlhOGJlMDg5IiwidGFnIjoiIn0%3D',
    'laravel-session=eyJpdiI6IkU5ZVU3SmtkOG90U3FydTJ1eHQ2blE9PSIsInZhbHVlIjoiNFpxdVJqLzVtREV2ZkpMSytPYnZPWERFbk4rT1JlUGcxWmIyc2FkcW15ZU1iYlVwdkIyN3Ayakk1TXBpbDltTVl5Ti9ZcG5WYzd5MGVCMnNJL0xmNW9PdW9uS3o0M3pDUDRSSlVRdFdRK25IcWowb01HMFpJa2JEeXRJTFZxUFAiLCJtYWMiOiIyNWMxMWZmM2Y5ZGY5NGQwZWVlOTY2NzhmOGU4ZGE5ZjE1YzBiZWYzNWMyYTFmMDM4MDJhN2E3NDk0OTQ3M2NiIiwidGFnIjoiIn0%3D',
];

$headers = [
    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
    'Accept-Encoding: gzip, deflate, br, zstd',
    'Accept-Language: en-US,en;q=0.9',
    'Cache-Control: max-age=0',
    'Sec-Fetch-Dest: document',
    'Sec-Fetch-Mode: navigate',
    'Sec-Fetch-Site: none',
    'Sec-Fetch-User: ?1',
    'Upgrade-Insecure-Requests: 1',
    'sec-ch-ua: "Not;A=Brand";v="8", "Chromium";v="150", "Google Chrome";v="150"',
    'sec-ch-ua-mobile: ?0',
    'sec-ch-ua-platform: "Windows"',
    'Cookie: '.implode('; ', $cookies),
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36',
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HEADER => true,
]);

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$err = curl_error($ch);
curl_close($ch);

echo "STATUS: {$status}\n";
echo "CONTENT-TYPE: {$contentType}\n";
if ($err) echo "CURL-ERROR: {$err}\n";

// Split headers from body
$headerSize = strpos($response, "\r\n\r\n") + 4;
$body = substr($response, $headerSize);

if (preg_match('/<title>(.*?)<\/title>/i', $body, $m)) {
    echo "PAGE TITLE: {$m[1]}\n";
} else {
    echo "PAGE TITLE: (no title tag)\n";
}

echo "--- BODY (first 1500 chars) ---\n";
echo substr($body, 0, 1500) . "\n";
