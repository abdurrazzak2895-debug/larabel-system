<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PaymentNotificationController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes proxy requests to the external SVP / Takamol API through
| the internal service layer. The /v1 prefix matches the documentation.
|
| The Bearer token supplied by the caller is forwarded unchanged to the
| external booking API — no local Sanctum authentication is applied.
|
*/

Route::prefix('v1')->group(function (): void {

    // -----------------------------------------------------------------
    // Authentication (no Bearer token)
    // -----------------------------------------------------------------
    Route::prefix('sessions')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/otp',   [AuthController::class, 'verifyOtp']);
    });

    // -----------------------------------------------------------------
    // Protected proxy endpoints (Bearer token forwarded upstream)
    // -----------------------------------------------------------------

    // Profile & Permissions
    Route::prefix('individual_labor_space')->group(function (): void {
        Route::get('/profile',           [ProfileController::class, 'profile']);
        Route::get('/permissions',       [ProfileController::class, 'permissions']);
        Route::get('/certificate_price', [ProfileController::class, 'certificatePrice']);
    });

    // Exam & Booking
    Route::prefix('individual_labor_space/exam_sessions')->group(function (): void {
        Route::get('/',                    [ExamController::class, 'sessions']);
        Route::get('/available_dates',     [ExamController::class, 'availableDates']);
        Route::post('/temporary_seats',    [ExamController::class, 'temporarySeat']);
    });

    Route::prefix('individual_labor_space/exam_reservations')->group(function (): void {
        Route::get('/',        [ExamController::class, 'reservations']);
        Route::get('/validate', [ExamController::class, 'validateReservation']);
    });

    // Exam lookups
    Route::prefix('individual_labor_space')->group(function (): void {
        Route::get('/occupations',    [ExamController::class, 'occupations']);
        Route::get('/cities',         [ExamController::class, 'cities']);
        Route::get('/categories',     [ExamController::class, 'categories']);
        Route::get('/exam_constraints', [ExamController::class, 'examConstraints']);
    });

    // Payment
    Route::prefix('individual_labor_space/payments')->group(function (): void {
        Route::get('/validate_pending', [PaymentNotificationController::class, 'validatePendingPayment']);
    });

    // Notification
    Route::get('individual_labor_space/notifications', [PaymentNotificationController::class, 'notifications']);

    // Verification
    Route::get('individual_labor_space/verification_requests', [PaymentNotificationController::class, 'verificationRequests']);
});
