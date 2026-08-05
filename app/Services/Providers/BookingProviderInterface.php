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

    // Exam
    public function examSessions(): JsonResponse;
    public function availableDates(): JsonResponse;
    public function temporarySeat(array $payload): JsonResponse;
    public function validateReservation(): JsonResponse;
    public function reservationDetails(): JsonResponse;
    public function occupations(): JsonResponse;
    public function cities(): JsonResponse;
    public function categories(): JsonResponse;
    public function examConstraints(): JsonResponse;

    // Payment / Notification / Verification
    public function validatePendingPayment(): JsonResponse;
    public function notifications(): JsonResponse;
    public function verificationRequests(): JsonResponse;
}
