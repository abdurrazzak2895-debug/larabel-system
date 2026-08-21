<?php

namespace App\Http\Controllers;

use App\Services\BookingService;
use App\Services\SvpAvailabilityDashboardService;
use App\Services\SvpAvailabilityTokenResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

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
        $categories = $tokens !== [] && ! $request->expectsJson()
            ? $this->cachedCategories($tokens)
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

    /**
     * Return verified sessions grouped by exact center and date.
     *
     * This endpoint is intentionally backed only by the server-managed SVP
     * account pool. It never reads the authenticated portal user's session
     * token and never creates a hold or reservation.
     */
    public function sessionPerCenterBot(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'category_id' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:150'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $validated = $validator->validated();
        $tokens = $this->resolveAvailabilityTokens();
        if ($tokens === []) {
            return response()->json([
                'success' => false,
                'message' => 'No usable backend SVP availability account is configured.',
            ], 503);
        }

        try {
            $result = $this->availability->lookup(
                $tokens,
                (string) $validated['category_id'],
                trim((string) $validated['city']),
                isset($validated['date']) ? (string) $validated['date'] : null,
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'category_id' => (string) $validated['category_id'],
                    'city' => trim((string) $validated['city']),
                    'date' => $validated['date'] ?? null,
                    'verified_only' => true,
                    'fetched_at' => $result['fetched_at'] ?? null,
                    'centers' => array_values($result['rows'] ?? []),
                ],
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'The backend SVP availability lookup failed. Please try again shortly.',
            ], 504);
        }
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
     * Load and cache the category catalog through the backend SVP account pool.
     * A successful non-empty response from any usable account wins; an empty
     * response from one account must not hide categories available from another.
     *
     * @param array<int, string> $tokens
     * @return array<int, mixed>
     */
    private function cachedCategories(array $tokens): array
    {
        try {
            return Cache::remember(
                'svp:availability:categories:v2',
            now()->addSeconds(max(1, (int) config('svp.availability_category_cache_ttl', 900))),
            function () use ($tokens): array {
                $attemptLimit = max(1, (int) config('svp.availability_account_attempts', 3));
                $successful = false;

                foreach (array_slice($tokens, 0, $attemptLimit) as $token) {
                    try {
                        $response = $this->booking->availabilityCategories($token);
                        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                            continue;
                        }

                        $successful = true;
                        $payload = $response->getData(true);
                        $categories = $this->extractFilterList($payload, ['data', 'categories']);
                        if ($categories !== []) {
                            return $categories;
                        }
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                }

                    return $successful ? [] : throw new \RuntimeException('All backend SVP category lookup accounts failed.');
                }
            );
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
