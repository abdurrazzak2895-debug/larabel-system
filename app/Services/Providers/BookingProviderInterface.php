<?php

namespace App\Services\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;

interface BookingProviderInterface
{
    public function withToken(string $token): static;

    // Auth
    public function login(array $payload): JsonResponse;
    public function verifyOtp(array $payload): JsonResponse;

    // Profile
    public function profile(): JsonResponse;
    public function permissions(): JsonResponse;
    public function certificatePrice(): JsonResponse;
    public function featureFlags(): JsonResponse;
    public function userBalance(string $userId): JsonResponse;

    // Exam
    public function examSessions(array $params = []): JsonResponse;
    public function examSession(string $id): JsonResponse;
    public function availableDates(): JsonResponse;
    public function temporarySeat(array $payload): JsonResponse;
    public function validateReservation(): JsonResponse;
    public function reservationDetails(?string $id = null): JsonResponse;
    public function createReservation(array $payload): JsonResponse;
    public function cancelReservation(string $id): JsonResponse;
    public function rescheduleReservation(string $id, array $payload): JsonResponse;
    public function useReservationCredit(array $payload): JsonResponse;
    public function occupations(): JsonResponse;
    public function occupationsSearch(?string $search = null, int $page = 1, int $perPage = 1000): JsonResponse;
    public function cities(?string $occupationId = null): JsonResponse;
    public function countries(): JsonResponse;
    public function testCentersForFilters(?string $city = null, ?string $occupationId = null): JsonResponse;
    public function categories(): JsonResponse;
    public function categoriesForOccupation(?string $occupationId = null): JsonResponse;
    public function examConstraints(): JsonResponse;
    public function examEngines(): JsonResponse;

    // Payment / Notification / Verification
    public function validatePendingPayment(): JsonResponse;
    public function payments(?string $id = null): JsonResponse;
    public function createPayment(array $payload): JsonResponse;
    public function updatePayment(string $id, array $payload): JsonResponse;
    public function notifications(): JsonResponse;
    public function verificationRequests(): JsonResponse;
}
