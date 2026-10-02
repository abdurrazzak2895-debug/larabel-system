<?php

namespace App\Services\Svp;

use App\Services\Providers\BookingProviderInterface;
use App\Services\T2Hub\T2HubBookingData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Resolve the SVP occupation id that belongs to the category of the selected
 * exam session.
 *
 * The T2Hub-driven wizard speaks in T2Hub occupation ids (for example 50 =
 * Barber). SVP expects its own occupation id for the session's category
 * (for example 2008 = Barber inside category 50) and answers HTTP 422
 * "selected occupation does not belong to the exam session category" when the
 * two id spaces are mixed. This resolver bridges them:
 *
 *   1. read the authoritative session detail to learn the session category,
 *   2. list the SVP occupation catalogue once (cached),
 *   3. keep only occupations inside that category,
 *   4. prefer an exact name match with the operator's T2Hub occupation, and
 *      accept the only candidate when the category exposes exactly one.
 *
 * It never invents an id: when the pairing stays ambiguous the submitted id is
 * left untouched so SVP's own validation message reaches the operator.
 */
class SvpOccupationResolver
{
    private const CATALOGUE_CACHE_KEY = 'svp:occupation_catalogue:v1';
    private const CATALOGUE_TTL = 900;
    private const SESSION_CATEGORY_TTL = 120;

    public function __construct(
        private BookingProviderInterface $provider,
        private T2HubBookingData $bridge,
    ) {
    }

    /**
     * @return array{occupation_id: string, submitted: string, resolved: bool, session_category_id: string, name: string, reason: string, candidates: int}
     */
    public function resolveForSession(string $token, string $sessionId, string $submittedOccupationId): array
    {
        $result = [
            'occupation_id' => $submittedOccupationId,
            'submitted' => $submittedOccupationId,
            'resolved' => false,
            'session_category_id' => '',
            'name' => '',
            'reason' => 'unresolved',
            'candidates' => 0,
        ];

        if ($token === '' || $sessionId === '' || $submittedOccupationId === '') {
            $result['reason'] = 'missing input';

            return $result;
        }

        try {
            $categoryId = $this->sessionCategoryId($token, $sessionId);
            if ($categoryId === '') {
                $result['reason'] = 'session category not exposed';

                return $result;
            }
            $result['session_category_id'] = $categoryId;

            $candidates = array_values(array_filter(
                $this->catalogue($token),
                static fn (array $occupation): bool => $occupation['category_id'] === $categoryId
            ));
            $result['candidates'] = count($candidates);

            if ($candidates === []) {
                $result['reason'] = 'no occupation belongs to the session category';

                return $result;
            }

            $match = $this->nameMatch($candidates, $this->occupationName($submittedOccupationId));
            if ($match === null && count($candidates) === 1) {
                // The session category exposes a single occupation, so the
                // operator's choice inside that category is unambiguous.
                $match = $candidates[0];
            }

            if ($match === null) {
                $result['reason'] = 'ambiguous occupation inside the session category';

                return $result;
            }

            $result['occupation_id'] = $match['id'];
            $result['name'] = $match['name'];
            $result['resolved'] = $match['id'] !== $submittedOccupationId;
            $result['reason'] = 'matched the session category catalogue';
        } catch (\Throwable $e) {
            Log::warning('SVP occupation resolution failed', [
                'session_id' => substr($sessionId, 0, 12),
                'submitted_occupation_id' => $submittedOccupationId,
                'error' => $e->getMessage(),
            ]);

            $result['reason'] = 'lookup failed';
        }

        return $result;
    }

    /**
     * The category id carried by the authoritative SVP session detail.
     */
    private function sessionCategoryId(string $token, string $sessionId): string
    {
        return (string) Cache::remember(
            'svp:session_category:'.hash('sha256', $sessionId),
            self::SESSION_CATEGORY_TTL,
            function () use ($token, $sessionId): string {
                // Read through the provider directly: SvpOccupationResolver is
                // injected into BookingService, so depending on BookingService
                // here would create a circular container dependency.
                $response = $this->provider->withToken($token)->examSession($sessionId);
                $payload = (array) $response->getData(true);
                $record = (array) (data_get($payload, 'data') ?? $payload);

                foreach ([
                    'category.id',
                    'category_id',
                    'exam_category_id',
                    'occupation.category_id',
                    'exam_engine.category_id',
                    'category.category_id',
                ] as $key) {
                    $value = data_get($record, $key);
                    if (is_scalar($value) && trim((string) $value) !== '') {
                        return trim((string) $value);
                    }
                }

                return '';
            }
        );
    }

    /**
     * Tenant-wide SVP occupation catalogue, flattened for comparison.
     *
     * @return array<int, array{id: string, name: string, category_id: string}>
     */
    private function catalogue(string $token): array
    {
        return Cache::remember(self::CATALOGUE_CACHE_KEY, self::CATALOGUE_TTL, function () use ($token): array {
            if (! method_exists($this->provider, 'occupationsSearch')) {
                return [];
            }

            $response = $this->provider->withToken($token)->occupationsSearch(null, 1, 1000);
            $payload = (array) $response->getData(true);
            $rows = data_get($payload, 'data.data')
                ?? data_get($payload, 'data')
                ?? data_get($payload, 'occupations')
                ?? $payload;

            if (! is_array($rows)) {
                return [];
            }

            $catalogue = [];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $id = trim((string) ($row['occupation_id'] ?? $row['id'] ?? ''));
                if ($id === '') {
                    continue;
                }

                $catalogue[] = [
                    'id' => $id,
                    'name' => $this->normalizeName((string) ($row['english_name'] ?? $row['name'] ?? '')),
                    'category_id' => trim((string) (data_get($row, 'category.id') ?? data_get($row, 'category_id') ?? '')),
                ];
            }

            return $catalogue;
        });
    }

    /**
     * The operator-facing occupation name kept by the T2Hub bridge.
     */
    private function occupationName(string $occupationId): string
    {
        try {
            if (config('t2hub.data_source') !== 't2hub') {
                return '';
            }

            foreach ($this->bridge->occupations() as $occupation) {
                if (! is_array($occupation)) {
                    continue;
                }
                if (trim((string) ($occupation['id'] ?? '')) === $occupationId) {
                    return $this->normalizeName((string) ($occupation['name'] ?? ''));
                }
            }
        } catch (\Throwable $e) {
            Log::warning('T2Hub occupation name lookup failed', [
                'occupation_id' => $occupationId,
                'error' => $e->getMessage(),
            ]);
        }

        return '';
    }

    /**
     * @param  array<int, array{id: string, name: string, category_id: string}>  $candidates
     */
    private function nameMatch(array $candidates, string $name): ?array
    {
        if ($name === '') {
            return null;
        }

        foreach ($candidates as $candidate) {
            if ($candidate['name'] !== '' && $candidate['name'] === $name) {
                return $candidate;
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate['name'] !== '' && (str_contains($candidate['name'], $name) || str_contains($name, $candidate['name']))) {
                return $candidate;
            }
        }

        return null;
    }

    private function normalizeName(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }
}