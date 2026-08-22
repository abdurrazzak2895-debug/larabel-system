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
        if (! in_array($path, self::ALLOWED_PATHS, true)) {
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
}
