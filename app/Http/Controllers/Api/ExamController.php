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
        $request->validate([
            'session_id' => ['nullable', 'string', 'max:255'],
            'category_id' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:120'],
            'exam_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->booking->availableDates(
            $this->svpToken($request),
            $request->query('session_id'),
            $request->only(['category_id', 'city', 'exam_date'])
        );
    }

    public function citiesForOccupation(Request $request): JsonResponse
    {
        $categoryId = $request->query('category_id');

        return $this->booking->cities($this->svpToken($request), $categoryId);
    }

    public function testCenters(Request $request): JsonResponse
    {
        $city = $request->query('city');
        $categoryId = $request->query('category_id');

        return $this->booking->testCenters($this->svpToken($request), $city, $categoryId);
    }

    /**
     * Create a temporary seat reservation.
     *
     * Accepts a JSON payload (session_id, candidate_id, date, …) and
     * forwards it to the SVP API.
     */
    public function temporarySeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'exam_session_id' => ['required', 'string', 'max:255'],
            'test_center_id' => ['required', 'string', 'max:100'],
        ]);

        return $this->booking->temporarySeat($this->svpToken($request), $data);
    }

    public function validateReservation(Request $request): JsonResponse
    {
        return $this->booking->validateReservation($this->svpToken($request));
    }

    public function reservations(Request $request): JsonResponse
    {
        return $this->booking->reservations($this->svpToken($request));
    }

    /**
     * Fetch a single exam session by id (e.g. GET /exam-sessions/{id}).
     */
    public function examSession(Request $request, string $session): JsonResponse
    {
        return $this->booking->examSession($this->svpToken($request), $session);
    }

    /**
     * Fetch a single reservation by id (e.g. GET /exam-reservations/{id}).
     */
    public function reservation(Request $request, string $reservation): JsonResponse
    {
        return $this->booking->reservation($this->svpToken($request), $reservation);
    }

    /**
     * Create a reservation on the external API (POST /exam-reservations).
     */
    public function storeReservation(Request $request): JsonResponse
    {
        return $this->booking->createReservation($this->svpToken($request), $request->all());
    }

    /**
     * Cancel a reservation (DELETE /exam-reservations/{id}).
     */
    public function cancelReservation(Request $request, string $reservation): JsonResponse
    {
        return $this->booking->cancelReservation($this->svpToken($request), $reservation);
    }

    /**
     * Reschedule a reservation (POST /exam-reservations/{id}/reschedule).
     */
    public function rescheduleReservation(Request $request, string $reservation): JsonResponse
    {
        return $this->booking->rescheduleReservation($this->svpToken($request), $reservation, $request->all());
    }

    /**
     * Consume a reservation credit (POST /reservation-credits/use).
     */
    public function useReservationCredit(Request $request): JsonResponse
    {
        return $this->booking->useReservationCredit($this->svpToken($request), $request->all());
    }

    public function occupations(Request $request): JsonResponse
    {
        return $this->booking->occupations($this->svpToken($request));
    }

    public function occupationsSearch(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 1000);

        return $this->booking->occupationsSearch($this->svpToken($request), $search, $page, $perPage);
    }

    public function cities(Request $request): JsonResponse
    {
        return $this->booking->cities($this->svpToken($request));
    }

    public function categories(Request $request): JsonResponse
    {
        return $this->booking->categories($this->svpToken($request));
    }

    public function countries(Request $request): JsonResponse
    {
        return $this->booking->countries($this->svpToken($request));
    }

    public function examEngines(Request $request): JsonResponse
    {
        return $this->booking->examEngines($this->svpToken($request));
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
