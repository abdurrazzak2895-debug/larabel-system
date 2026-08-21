<?php

namespace App\Http\Controllers;

use App\Services\BookingService;
use App\Services\SvpAvailabilityDashboardService;
use App\Services\SvpAvailabilityTokenResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SvpAvailabilityDashboardController extends Controller
{
    public function __construct(
        private SvpAvailabilityDashboardService $availability,
        private BookingService $booking,
        private SvpAvailabilityTokenResolver $tokens,
    ) {
        $this->middleware('auth.multi');
    }

    public function index(Request $request)
    {
        $tokens = $this->resolveAvailabilityTokens();
        $token = $tokens[0] ?? null;
        $categoryId = trim((string) $request->query('category_id', ''));
        $city = trim((string) $request->query('city', ''));
        $date = $request->query('date');
        $categories = $token && ! $request->expectsJson()
            ? $this->booking->availabilityCategories($token)->getData(true)
            : [];
        $cities = $tokens !== [] && $categoryId !== '' ? $this->cachedCities($tokens, $categoryId) : [];
        $result = ['rows' => [], 'fetched_at' => null];

        // Never start the expensive center/session fan-out until the user has
        // selected a city. Category changes use the dedicated cached endpoint.
        if ($tokens !== [] && $categoryId !== '' && $city !== '') {
            $result = $this->availability->lookup($tokens, $categoryId, $city, is_string($date) ? $date : null);
        }

        if ($request->expectsJson()) {
            if ($tokens === []) {
                return response()->json([
                    'success' => false,
                    'message' => 'No usable backend SVP availability account is configured.',
                ], 503);
            }

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        }

        return view('availability.index', compact('categories', 'cities', 'categoryId', 'city', 'date', 'result'));
    }

    /** Return category-scoped cities from a backend-account-backed cache. */
    public function cities(Request $request)
    {
        $categoryId = trim((string) $request->query('category_id', ''));
        if ($categoryId === '') {
            return response()->json([
                'success' => false,
                'message' => 'A category_id is required.',
            ], 422);
        }

        $tokens = $this->resolveAvailabilityTokens();
        $token = $tokens[0] ?? null;
        if (! $token) {
            return response()->json([
                'success' => false,
                'message' => 'No usable backend SVP availability account is configured.',
            ], 503);
        }

        try {
            $cities = $this->cachedCities($tokens, $categoryId);

            return response()->json([
                'success' => true,
                'data' => $this->extractFilterList($cities, ['cities', 'data']),
                'cached' => true,
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'The backend SVP city lookup timed out. Please try again shortly.',
            ], 504);
        }
    }

    /** @return array<int, string> */
    private function resolveAvailabilityTokens(): array
    {
        try {
            // Deliberately do not read the authenticated portal user session here.
            return $this->tokens->resolvePool();
        } catch (\Throwable $exception) {
            report($exception);

            return [];
        }
    }

    /**
     * @param array<int, string> $tokens
     * @return array<string, mixed>
     */
    private function cachedCities(array $tokens, string $categoryId): array
    {
        $cacheKey = 'svp:availability:cities:v2:'.sha1(json_encode([
            'category_id' => $categoryId,
            'country_id' => config('svp.country_id', 78),
        ]));

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(max(1, (int) config('svp.availability_city_cache_ttl', 900))),
            function () use ($tokens, $categoryId): array {
                $attemptLimit = max(1, (int) config('svp.availability_account_attempts', 3));
                foreach (array_slice($tokens, 0, $attemptLimit) as $token) {
                    try {
                        $response = $this->booking->availabilityCities($token, $categoryId);
                        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                            return $response->getData(true);
                        }
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                }

                throw new \RuntimeException('All backend SVP city lookup accounts failed.');
            }
        );
    }

    private function extractFilterList(array $payload, array $keys): array
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (! is_array($value)) {
                continue;
            }

            if (array_is_list($value)) {
                return array_values($value);
            }

            foreach (['cities', 'categories', 'data', 'items', 'results'] as $nestedKey) {
                $nested = $value[$nestedKey] ?? null;
                if (is_array($nested) && array_is_list($nested)) {
                    return array_values($nested);
                }
            }
        }

        return [];
    }
}
