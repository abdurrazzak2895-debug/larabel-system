<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\BookingService;

class ExamController
{
    /**
     * @param  BookingService  $booking
     */
    public function __construct(private BookingService $booking) {}

    public function sessions(Request $request): JsonResponse
    {
        return $this->booking->sessions($this->svpToken($request), $request->query());
    }

    public function availableDates(Request $request): JsonResponse
    {
        return $this->booking->availableDates($this->svpToken($request));
    }

    public function citiesForOccupation(Request $request): JsonResponse
    {
        $occupationId = $request->query('occupation_id');

        return $this->booking->cities($this->svpToken($request), $occupationId);
    }

    public function testCenters(Request $request): JsonResponse
    {
        $city = $request->query('city');
        $occupationId = $request->query('occupation_id');

        return $this->booking->testCenters($this->svpToken($request), $city, $occupationId);
    }

    /**
     * Create a temporary seat reservation.
     *
     * Accepts a JSON payload (session_id, candidate_id, date, …) and
     * forwards it to the SVP API.
     */
    public function temporarySeat(Request $request): JsonResponse
    {
        return $this->booking->temporarySeat($this->svpToken($request), $request->all());
    }

    public function validateReservation(Request $request): JsonResponse
    {
        return $this->booking->validateReservation($this->svpToken($request));
    }

    public function reservations(Request $request): JsonResponse
    {
        return $this->booking->reservations($this->svpToken($request));
    }

    public function occupations(Request $request): JsonResponse
    {
        return $this->booking->occupations($this->svpToken($request));
    }

    public function cities(Request $request): JsonResponse
    {
        return $this->booking->cities($this->svpToken($request));
    }

    public function categories(Request $request): JsonResponse
    {
        return $this->booking->categories($this->svpToken($request));
    }

    public function categoriesForOccupation(Request $request): JsonResponse
    {
        $occupationId = $request->query('occupation_id');

        return $this->booking->categoriesForOccupation($this->svpToken($request), $occupationId);
    }

    public function examConstraints(Request $request): JsonResponse
    {
        return $this->booking->examConstraints($this->svpToken($request));
    }

    /**
     * Extract the SVP Bearer token from the incoming request.
     *
     * Falls back to an empty string if the header is absent so the
     * provider can surface the upstream 401 instead of masking it.
     */
    private function svpToken(Request $request): string
    {
        $bearer = $request->bearerToken();

        return is_string($bearer) ? $bearer : '';
    }
}
