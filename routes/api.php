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
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
        Route::post('/otp',   [AuthController::class, 'verifyOtp'])->middleware('throttle:10,1');
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
        Route::get('/',                 [ExamController::class, 'sessions']);
        Route::get('/available_dates',  [ExamController::class, 'availableDates']);
        Route::get('/{session}',        [ExamController::class, 'examSession']);
    });

    Route::prefix('individual_labor_space/exam_reservations')->group(function (): void {
        Route::get('/',                         [ExamController::class, 'reservations']);
        Route::get('/validate',                  [ExamController::class, 'validateReservation']);
        Route::post('/',                         [ExamController::class, 'storeReservation']);
        Route::get('/{reservation}',             [ExamController::class, 'reservation']);
        Route::delete('/{reservation}',          [ExamController::class, 'cancelReservation']);
        Route::post('/{reservation}/reschedule', [ExamController::class, 'rescheduleReservation']);
    });

    Route::prefix('individual_labor_space')->group(function (): void {
        Route::post('/temporary_seats',         [ExamController::class, 'temporarySeat']);
        Route::post('/reservation_credits/use', [ExamController::class, 'useReservationCredit']);
    });

    // Exam lookups
    Route::prefix('individual_labor_space')->group(function (): void {
        Route::get('/occupations',      [ExamController::class, 'occupations']);
        Route::get('/cities',           [ExamController::class, 'cities']);
        Route::get('/countries',        [ExamController::class, 'countries']);
        Route::get('/categories',       [ExamController::class, 'categories']);
        Route::get('/exam_engines',     [ExamController::class, 'examEngines']);
        Route::get('/exam_constraints', [ExamController::class, 'examConstraints']);
        Route::get('/test_centers',     [ExamController::class, 'testCenters']);
    });

    // Payment
    Route::prefix('individual_labor_space/payments')->group(function (): void {
        Route::get('/validate_pending', [PaymentNotificationController::class, 'validatePendingPayment']);
        Route::get('/',                 [PaymentNotificationController::class, 'payments']);
        Route::post('/',                [PaymentNotificationController::class, 'storePayment']);
        Route::get('/{payment}',        [PaymentNotificationController::class, 'showPayment']);
        Route::put('/{payment}',        [PaymentNotificationController::class, 'updatePayment']);
    });

    // Feature flags & user balance (mirror the official SVP paths)
    Route::prefix('flipper')->group(function (): void {
        Route::get('/feature_flags', [ProfileController::class, 'featureFlags']);
    });

    Route::prefix('users')->group(function (): void {
        Route::get('/{user}/balance', [ProfileController::class, 'userBalance']);
    });

    // Notification / Verification
    Route::prefix('individual_labor_space')->group(function (): void {
        Route::get('/notifications',         [PaymentNotificationController::class, 'notifications']);
        Route::get('/verification_requests', [PaymentNotificationController::class, 'verificationRequests']);
    });
});

// ---------------------------------------------------------------------
// SVP proxy route: forward browser or API calls to the SVP host via
// our server. This keeps the upstream host hidden and ensures headers
// (Authorization, X-Tenant-Name, X-CSRF-Token) are set server-side.
// ---------------------------------------------------------------------
Route::any('svp/{any}', [\App\Http\Controllers\SvpProxyController::class, 'proxy'])
    ->where('any', '.*')
    ->middleware([\App\Http\Middleware\HandleSvpCors::class, 'throttle:60,1']);
