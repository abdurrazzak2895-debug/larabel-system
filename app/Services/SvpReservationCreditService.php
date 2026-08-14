<?php

namespace App\Services;

use App\Models\Candidate;
use App\Services\Providers\BookingProviderInterface;
use RuntimeException;

/**
 * Reads the authenticated SVP user's reservation-credit balance for an exact
 * occupation and methodology. The official SVP SPA calls the same endpoint:
 * GET /users/{user}/balance?methodology_type=&occupation_id=.
 */
class SvpReservationCreditService
{
    public function __construct(private BookingProviderInterface $provider)
    {
    }

    /**
     * @return array{credits: int, raw: array<string, mixed>}
     */
    public function status(string $token, Candidate $candidate, string $occupationId, string $methodology): array
    {
        return $this->statusForUser($token, (string) $candidate->svp_user_id, $occupationId, $methodology);
    }

    /**
     * @return array{credits: int, raw: array<string, mixed>}
     */
    public function statusForUser(string $token, string $svpUserId, string $occupationId, string $methodology): array
    {
        $svpUserId = trim($svpUserId);

        if ($svpUserId === '') {
            throw new RuntimeException('The selected candidate has no synced SVP user ID. Sign in with SVP again to refresh the candidate profile.');
        }

        $response = $this->provider
            ->withToken($token)
            ->userBalance($svpUserId, [
                'methodology_type' => $methodology,
                'occupation_id' => $occupationId,
            ]);

        $body = $response->getData(true);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            $message = data_get($body, 'message')
                ?? data_get($body, 'error')
                ?? 'SVP could not load the reservation-credit balance.';

            throw new RuntimeException((string) $message);
        }

        $credits = data_get($body, 'reservation_credits', data_get($body, 'data.reservation_credits', 0));

        return [
            'credits' => max(0, (int) $credits),
            'raw' => is_array($body) ? $body : [],
        ];
    }
}
