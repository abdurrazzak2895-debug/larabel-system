<?php

namespace App\Http\Controllers;

use App\Services\BookingService;
use App\Services\SvpAvailabilityDashboardService;
use Illuminate\Http\Request;

class SvpAvailabilityDashboardController extends Controller
{
    public function __construct(
        private SvpAvailabilityDashboardService $availability,
        private BookingService $booking,
    ) {
        $this->middleware('auth.multi');
    }

    public function index(Request $request)
    {
        $token = $request->session()->get('svp_token');
        $categoryId = trim((string) $request->query('category_id', ''));
        $city = trim((string) $request->query('city', 'Dhaka'));
        $date = $request->query('date');

        $categories = $token ? $this->booking->categories($token)->getData(true) : [];
        $cities = $token ? $this->booking->cities($token, $categoryId ?: null)->getData(true) : [];
        $result = ['rows' => [], 'fetched_at' => null];

        if ($token && $categoryId !== '' && $city !== '') {
            $result = $this->availability->lookup($token, $categoryId, $city, is_string($date) ? $date : null);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'data' => $result,
                'filters' => [
                    'categories' => $this->extractFilterList($categories, ['categories', 'data']),
                    'cities' => $this->extractFilterList($cities, ['cities', 'data']),
                ],
            ]);
        }

        return view('availability.index', compact('categories', 'cities', 'categoryId', 'city', 'date', 'result'));
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
