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
            ]);
        }

        return view('availability.index', compact('categories', 'cities', 'categoryId', 'city', 'date', 'result'));
    }
}
