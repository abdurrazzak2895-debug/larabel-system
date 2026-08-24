<?php

namespace App\Services\Providers;

use App\Contracts\PortalAvailabilityProviderInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class PortalAvailabilityProvider implements PortalAvailabilityProviderInterface
{
    private const ALLOWED_PATHS = [
        '/api/occupations',
        '/api/search_dates',
        '/api/centers',
    ];

    public function refreshAccount(string $sessionCookie, string $accountId): array
    {
        $accountId = trim($accountId);
        if ($accountId === '' || preg_match('/^[A-Za-z0-9_-]+$/', $accountId) !== 1) {
            throw new RuntimeException('Portal account ID is invalid.');
        }

        $path = sprintf((string) config('portal.refresh_path', '/api/accounts/%s/refresh'), rawurlencode($accountId));
        $response = $this->client($sessionCookie)->post($path);
        $body = $response->json();
        $this->decode($path, $response->status(), $body);

        $refreshedCookie = $this->refreshedCookie($response, is_array($body) ? $body : [], $sessionCookie);
        $expiresAt = $this->refreshExpiry(is_array($body) ? $body : []);

        return [
            'session_cookie' => $refreshedCookie,
            'expires_at' => $expiresAt,
            'rotated' => $refreshedCookie !== null && $refreshedCookie !== trim($sessionCookie),
        ];
    }

    public function occupations(string $sessionCookie): array
    {
        $body = $this->get('/api/occupations', $sessionCookie);

        if (array_is_list($body)) {
            return $body;
        }

        $items = $body['occupations'] ?? $body['data'] ?? $body['items'] ?? null;
        return is_array($items) ? array_values($items) : [];
    }

    public function searchDates(
        string $sessionCookie,
        string $accountId,
        int|string $categoryId,
        string $startFrom,
    ): array {
        return $this->post('/api/search_dates', $sessionCookie, [
            'account_id' => $accountId,
            'category_id' => $categoryId,
            'start_from' => $startFrom,
        ]);
    }

    public function centers(
        string $sessionCookie,
        string $accountId,
        int|string $categoryId,
        string $city,
        string $date,
        int|string $occupationId,
        string $languageCode,
    ): array {
        return $this->post('/api/centers', $sessionCookie, [
            'account_id' => $accountId,
            'category_id' => $categoryId,
            'city' => $city,
            'date' => $date,
            'occupation_id' => $occupationId,
            'language_code' => $languageCode,
        ]);
    }

    private function client(string $sessionCookie): PendingRequest
    {
        $sessionCookie = trim($sessionCookie);
        if ($sessionCookie === '') {
            throw new RuntimeException('Portal session is not configured.');
        }

        return Http::baseUrl(rtrim((string) config('portal.base_url'), '/'))
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'Cookie' => $sessionCookie,
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->connectTimeout((int) config('portal.connect_timeout', 5))
            ->timeout((int) config('portal.timeout', 15));
    }

    private function get(string $path, string $sessionCookie): array
    {
        $response = $this->client($sessionCookie)->get($path);
        return $this->decode($path, $response->status(), $response->json());
    }

    private function post(string $path, string $sessionCookie, array $payload): array
    {
        $response = $this->client($sessionCookie)->post($path, $payload);
        return $this->decode($path, $response->status(), $response->json());
    }

    private function decode(string $path, int $status, mixed $body): array
    {
        $allowed = in_array($path, self::ALLOWED_PATHS, true)
            || preg_match('#^/api/accounts/[A-Za-z0-9_-]+/refresh$#', $path) === 1;
        if (! $allowed) {
            throw new RuntimeException('Blocked non-availability portal endpoint.');
        }

        if ($status === 401 || $status === 403) {
            throw new RuntimeException('Portal session expired or is not authorized.');
        }

        if ($status < 200 || $status >= 300 || ! is_array($body)) {
            throw new RuntimeException("Portal availability request failed for {$path} with HTTP {$status}.");
        }

        return $body;
    }

    private function refreshedCookie(mixed $response, array $body, string $previousCookie): ?string
    {
        $cookies = [];
        $setCookies = $response->toPsrResponse()->getHeader('Set-Cookie');
        foreach ($setCookies as $setCookie) {
            $pair = trim(explode(';', $setCookie, 2)[0]);
            if ($pair !== '' && str_contains($pair, '=')) {
                [$name, $value] = explode('=', $pair, 2);
                $name = trim($name);
                if ($name !== '') {
                    $cookies[$name] = $name.'='.trim($value);
                }
            }
        }

        foreach ($this->cookieCandidates($body) as $candidate) {
            foreach ($this->cookiePairs($candidate) as $name => $pair) {
                $cookies[$name] = $pair;
            }
        }

        if ($cookies === []) {
            return null;
        }

        foreach ($this->cookiePairs($previousCookie) as $name => $pair) {
            $cookies[$name] ??= $pair;
        }

        return implode('; ', array_values($cookies));
    }

    /** @return array<int, string> */
    private function cookieCandidates(array $body): array
    {
        $candidates = [];
        foreach ([
            data_get($body, 'session_cookie'),
            data_get($body, 'cookie'),
            data_get($body, 'set_cookie'),
            data_get($body, 'data.session_cookie'),
            data_get($body, 'data.cookie'),
            data_get($body, 'data.set_cookie'),
            data_get($body, 'result.session_cookie'),
            data_get($body, 'result.cookie'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $candidates[] = trim($candidate);
            }
        }

        return $candidates;
    }

    /** @return array<string, string> */
    private function cookiePairs(string $header): array
    {
        $pairs = [];
        foreach (preg_split('/;\\s*/', trim($header)) ?: [] as $part) {
            if (! str_contains($part, '=')) {
                continue;
            }
            [$name, $value] = explode('=', $part, 2);
            $name = trim($name);
            $value = trim($value);
            if ($name !== '' && $value !== '' && preg_match('/^[A-Za-z0-9_!#$%&\'*+.^`|~-]+$/', $name) === 1) {
                $pairs[$name] = $name.'='.$value;
            }
        }

        return $pairs;
    }

    private function refreshExpiry(array $body): ?string
    {
        foreach ([
            data_get($body, 'expires_at'),
            data_get($body, 'expiresAt'),
            data_get($body, 'data.expires_at'),
            data_get($body, 'data.expiresAt'),
            data_get($body, 'result.expires_at'),
        ] as $value) {
            if (is_string($value) && trim($value) !== '' && strtotime($value) !== false) {
                return date('Y-m-d H:i:s', strtotime($value));
            }
        }

        return null;
    }
}
