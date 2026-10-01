<?php

namespace App\Http\Controllers;

use App\Services\T2Hub\T2HubClient;
use App\Services\T2Hub\T2HubProvider;
use App\Services\T2Hub\T2HubSessionStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Booking-data endpoints backed by the T2Hub agent portal.
 *
 * The booking page loads occupations, cities, dates, centres and sessions from
 * here, so the browser never talks to the portal directly and the agent
 * session never leaves the server.
 */
class T2HubController extends Controller
{
    public function __construct(
        private readonly T2HubClient $client,
        private readonly T2HubProvider $provider,
        private readonly T2HubSessionStore $store,
    ) {
    }

    /**
     * GET /booking-data/status — session and configuration health (no secrets).
     */
    public function status(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'data_source' => config('t2hub.data_source'),
                'base_url' => $this->client->baseUrl(),
                'app_path' => $this->client->appPath(),
                'credentials_configured' => $this->client->configured(),
                'fallback_session_configured' => filled(config('t2hub.session_cookie')) && filled(config('t2hub.session_key')),
                'session' => $this->store->summary(),
            ],
        ]);
    }

    /**
     * POST /booking-data/session — force a fresh portal login.
     */
    public function refresh(): JsonResponse
    {
        try {
            $this->client->login();

            return response()->json([
                'success' => true,
                'data' => ['session' => $this->store->summary()],
            ]);
        } catch (\Throwable $e) {
            Log::warning('T2Hub session refresh failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'data' => ['session' => $this->store->summary()],
            ], 502);
        }
    }

    /**
     * GET /booking-data/occupations
     */
    public function occupations(Request $request): JsonResponse
    {
        return $this->respond(fn () => [
            'occupations' => $this->provider->occupations($request->query('search')),
        ]);
    }

    /**
     * GET /booking-data/cities?category_id=…
     */
    public function cities(Request $request): JsonResponse
    {
        $request->validate(['category_id' => 'required|string']);

        return $this->respond(function () use ($request) {
            $categoryId = (string) $request->query('category_id');

            return [
                'category_id' => $categoryId,
                'cities' => $this->provider->cities($categoryId),
            ];
        });
    }

    /**
     * GET /booking-data/dates?category_id=…&city=…
     */
    public function dates(Request $request): JsonResponse
    {
        $request->validate([
            'category_id' => 'required|string',
            'city' => 'required|string',
        ]);

        return $this->respond(fn () => $this->provider->availableDates(
            (string) $request->query('category_id'),
            (string) $request->query('city'),
        ));
    }

    /**
     * GET /booking-data/centers?city=…
     */
    public function centers(Request $request): JsonResponse
    {
        $request->validate(['city' => 'required|string']);

        return $this->respond(fn () => ['sites' => $this->provider->testCenters((string) $request->query('city'))]);
    }

    /**
     * GET /booking-data/sessions?category_id=…&city=…&exam_date=…[&test_center_id=…]
     */
    public function sessions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => 'required|string',
            'city' => 'required|string',
            'exam_date' => 'required|date_format:Y-m-d',
            'test_center_id' => 'nullable|string',
        ]);

        return $this->respond(function () use ($data) {
            $result = $this->provider->sessions(
                (string) $data['category_id'],
                (string) $data['city'],
                (string) $data['exam_date'],
                $data['test_center_id'] ?? null,
            );

            return [
                'sessions' => $result['sessions'],
                'exam_sessions' => $result['sessions'],
                'sites' => $result['sites'],
            ];
        });
    }

    /**
     * POST /booking-data/bootstrap — one call for the whole chain.
     *
     * Body: { category_id?, city?, exam_date?, resource? }
     *  - category + city + date → sessions plus the centres that have them
     *  - resource=centers + city → every centre in the city
     *  - category + city         → available dates
     *  - nothing                 → occupation catalogue
     */
    public function bootstrap(Request $request): JsonResponse
    {
        $categoryId = trim((string) $request->input('category_id', ''));
        $city = trim((string) $request->input('city', ''));
        $examDate = trim((string) $request->input('exam_date', ''));
        $resource = trim((string) $request->input('resource', ''));

        if ($resource === 'centers' && $city !== '') {
            return $this->respond(fn () => ['sites' => $this->provider->testCenters($city)]);
        }

        if ($categoryId !== '' && $city !== '' && $examDate !== '') {
            return $this->respond(function () use ($categoryId, $city, $examDate) {
                $result = $this->provider->sessions($categoryId, $city, $examDate);

                return [
                    'sessions' => $result['sessions'],
                    'exam_sessions' => $result['sessions'],
                    'sites' => $result['sites'],
                ];
            });
        }

        if ($categoryId !== '' && $city !== '') {
            return $this->respond(fn () => $this->provider->availableDates($categoryId, $city));
        }

        return $this->respond(fn () => ['occupations' => $this->provider->occupations()]);
    }

    /**
     * Run a portal call and translate failures into a clean JSON error.
     *
     * @param  callable(): array<string, mixed>  $callback
     */
    private function respond(callable $callback): JsonResponse
    {
        try {
            return response()->json(['success' => true, 'data' => $callback()]);
        } catch (\Throwable $e) {
            Log::warning('T2Hub booking data failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => 'Booking data is temporarily unavailable.',
                'detail' => $e->getMessage(),
            ], 502);
        }
    }
}
