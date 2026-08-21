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
        $token = $this->resolveAvailabilityToken();
        $categoryId = trim((string) $request->query('category_id', ''));
        $city = trim((string) $request->query('city', ''));
        $date = $request->query('date');
        $categories = $token && ! $request->expectsJson()
            ? $this->booking->availabilityCategories($token)->getData(true)
            : [];
        $cities = $token && $categoryId !== '' ? $this->cachedCities($token, $categoryId) : [];
        $result = ['rows' => [], 'fetched_at' => null];

        // Never start the expensive center/session fan-out until the user has
        // selected a city. Category changes use the dedicated cached endpoint.
        if ($token && $categoryId !== '' && $city !== '') {
            $result = $this->availability->lookup($token, $categoryId, $city, is_string($date) ? $date : null);
        }

        if ($request->expectsJson()) {
            if (! $token) {
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

        $token = $this->resolveAvailabilityToken();
        if (! $token) {
            return response()->json([
                'success' => false,
                'message' => 'No usable backend SVP availability account is configured.',
            ], 503);
        }

        try {
            $cities = $this->cachedCities($token, $categoryId);

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

    private function resolveAvailabilityToken(): ?string
    {
        try {
            // Deliberately do not read the authenticated portal user session here.
            return $this->tokens->resolve();
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function cachedCities(string $token, string $categoryId): array
    {
        $cacheKey = 'svp:availability:cities:'.sha1(json_encode([
            'category_id' => $categoryId,
            'country_id' => config('svp.country_id', 78),
        ]));

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(max(1, (int) config('svp.availability_city_cache_ttl', 900))),
            fn (): array => $this->booking->availabilityCities($token, $categoryId)->getData(true)
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
