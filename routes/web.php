<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DepositController as AdminDepositController;
use App\Http\Controllers\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Admin\PricingController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\RefundController as AdminRefundController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Admin\WalletController as AdminWalletController;
use App\Http\Controllers\Admin\AgencyController;
use App\Http\Controllers\Admin\TestCenterController;
use App\Http\Controllers\Admin\PortalAvailabilityController;
use App\Http\Controllers\Admin\SvpAvailabilityAccountController;
use App\Http\Controllers\Agency\DashboardController as AgencyDashboardController;
use App\Http\Controllers\Agency\DepositController as AgencyDepositController;
use App\Http\Controllers\Agency\NotificationController as AgencyNotificationController;
use App\Http\Controllers\Agency\RefundController as AgencyRefundController;
use App\Http\Controllers\Agency\ReportController as AgencyReportController;
use App\Http\Controllers\Agency\UserController as AgencyUserController;
use App\Http\Controllers\Agency\WalletController as AgencyWalletController;
use App\Http\Controllers\AgencyPanelController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\SvpLoginController;
use App\Http\Controllers\SvpSessionVerificationController;
use App\Http\Controllers\SvpAvailabilityDashboardController;
use App\Http\Controllers\User\BookingController as UserBookingController;
use App\Http\Controllers\User\DashboardController as UserDashboardController;
use App\Http\Controllers\User\DepositController as UserDepositController;
use App\Http\Controllers\User\NotificationController as UserNotificationController;
use App\Http\Controllers\User\RefundController as UserRefundController;
use App\Http\Controllers\User\WalletController as UserWalletController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
})->middleware('web');

// -------------------------------
// Authentication
// -------------------------------
Route::middleware('web')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:5,1')->name('login.attempt');
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    // -------------------------------
    // SVP / Takamol real API login (email+password -> OTP -> bearer token)
    // -------------------------------
    Route::get('/svp/login', [SvpLoginController::class, 'showLoginForm'])->name('svp.login.form');
    Route::post('/svp/login', [SvpLoginController::class, 'login'])->middleware('throttle:5,1')->name('svp.login.attempt');
    Route::get('/svp/otp', [SvpLoginController::class, 'showOtpForm'])->name('svp.otp.form');
    Route::post('/svp/otp/verify', [SvpLoginController::class, 'verifyOtp'])->middleware('throttle:10,1')->name('svp.otp.verify');
    Route::post('/svp/otp/resend', [SvpLoginController::class, 'resendOtp'])->middleware('throttle:3,1')->name('svp.otp.resend');

    Route::get('/availability/cities', [SvpAvailabilityDashboardController::class, 'cities'])
        ->middleware('auth.multi')
        ->name('svp.availability.cities');
    Route::get('/availability', [SvpAvailabilityDashboardController::class, 'index'])
        ->middleware('auth.multi')
        ->name('svp.availability');
    Route::get('/sessionpercenterbot', [SvpAvailabilityDashboardController::class, 'sessionPerCenterBot'])
        ->middleware('auth.multi')
        ->name('svp.session-per-center-bot');

    // -------------------------------
    // Super Admin panel
    // -------------------------------
    Route::prefix('admin')->name('admin.')->middleware(['auth.multi', 'CheckPermission:manage_agencies'])->group(function () {
    Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');

    // Agencies
    Route::resource('agencies', AgencyController::class);
    Route::post('/agencies/{agency}/suspend', [AgencyController::class, 'suspend'])->name('agencies.suspend');
    Route::post('/agencies/{agency}/activate', [AgencyController::class, 'activate'])->name('agencies.activate');

    // Users
    Route::resource('users', AdminUserController::class);
    Route::post('/users/{user}/disable', [AdminUserController::class, 'disable'])->name('users.disable');
    Route::post('/users/{user}/reset-password', [AdminUserController::class, 'resetPassword'])->name('users.reset-password');

    // Wallets
    Route::prefix('wallets')->name('wallets.')->group(function () {
        Route::get('/', [AdminWalletController::class, 'index'])->name('index');
        Route::get('/{agency}', [AdminWalletController::class, 'show'])->name('show');
        Route::post('/{agency}/credit', [AdminWalletController::class, 'credit'])->name('credit');
        Route::post('/{agency}/debit', [AdminWalletController::class, 'debit'])->name('debit');
        Route::post('/{agency}/freeze', [AdminWalletController::class, 'freeze'])->name('freeze');
    });

    // Pricing
    Route::get('/pricing', [PricingController::class, 'index'])->name('pricing.index');
    Route::put('/pricing', [PricingController::class, 'update'])->name('pricing.update');

    // Deposits
    Route::prefix('deposits')->name('deposits.')->group(function () {
        Route::get('/', [AdminDepositController::class, 'index'])->name('index');
        Route::post('/{deposit}/approve', [AdminDepositController::class, 'approve'])->name('approve');
        Route::post('/{deposit}/reject', [AdminDepositController::class, 'reject'])->name('reject');
    });

    // Refunds
    Route::prefix('refunds')->name('refunds.')->group(function () {
        Route::get('/', [AdminRefundController::class, 'index'])->name('index');
        Route::post('/{refund}/approve', [AdminRefundController::class, 'approve'])->name('approve');
        Route::post('/{refund}/reject', [AdminRefundController::class, 'reject'])->name('reject');
    });

    // Reports
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [AdminReportController::class, 'index'])->name('index');
        Route::get('/{type}', [AdminReportController::class, 'agencyReport'])->name('agency');
    });

    // Audit Logs
    Route::resource('audit-logs', AuditLogController::class)->only(['index']);

    // Test Centers (real SVP API sync + local rows)
    Route::prefix('test-centers')->name('test-centers.')->group(function () {
        Route::get('/', [TestCenterController::class, 'index'])->name('index');
        Route::post('/sync', [TestCenterController::class, 'sync'])->name('sync');
    });

    // Portal availability adapter (read-only occupations, dates, and centers)
    Route::prefix('portal-availability')->name('portal-availability.')->group(function () {
        Route::get('/', [PortalAvailabilityController::class, 'index'])->name('index');
        Route::post('/credentials', [PortalAvailabilityController::class, 'storeCredential'])->name('credentials.store');
        Route::put('/credentials/{credential}', [PortalAvailabilityController::class, 'updateCredential'])->name('credentials.update');
        Route::post('/credentials/{credential}/activate', [PortalAvailabilityController::class, 'activate'])->name('credentials.activate');
        Route::post('/credentials/{credential}/deactivate', [PortalAvailabilityController::class, 'deactivate'])->name('credentials.deactivate');
        Route::get('/occupations', [PortalAvailabilityController::class, 'occupations'])->name('occupations');
        Route::post('/dates', [PortalAvailabilityController::class, 'dates'])->name('dates');
        Route::post('/centers', [PortalAvailabilityController::class, 'centers'])->name('centers');
    });

    // Backend-managed SVP availability accounts (read-only availability only)
    Route::prefix('svp-availability-accounts')->name('svp-availability-accounts.')->group(function () {
        Route::get('/', [SvpAvailabilityAccountController::class, 'index'])->name('index');
        Route::post('/', [SvpAvailabilityAccountController::class, 'store'])->name('store');
        Route::post('/{account}/token', [SvpAvailabilityAccountController::class, 'seedToken'])->name('token');
        Route::post('/{account}/activate', [SvpAvailabilityAccountController::class, 'activate'])->name('activate');
        Route::post('/{account}/deactivate', [SvpAvailabilityAccountController::class, 'deactivate'])->name('deactivate');
    });

    // Settings
    Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');

    // Notifications
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [AdminNotificationController::class, 'index'])->name('index');
        Route::post('/{notification}/mark-read', [AdminNotificationController::class, 'markRead'])->name('mark-read');
        Route::post('/mark-all-read', [AdminNotificationController::class, 'markAllRead'])->name('mark-all-read');
    });
});
}); // close web group started on line 39

// -------------------------------
// Agency panel
// -------------------------------
Route::middleware('web')->prefix('agency')->name('agency.')->middleware(['auth.multi', 'agency.scope'])->group(function () {
    Route::get('/dashboard', [AgencyDashboardController::class, 'index'])->name('dashboard');

    // Bookings
    Route::get('/bookings',             [\App\Http\Controllers\Agency\BookingController::class, 'index'])->name('bookings.index');
    Route::get('/bookings/create',      [\App\Http\Controllers\Agency\BookingController::class, 'create'])->name('bookings.create');
    Route::post('/bookings',            [\App\Http\Controllers\Agency\BookingController::class, 'store'])->name('bookings.store');
    // NOTE: /bookings/{booking} is registered LAST so the lookups below
    // (available-dates, lookup/*) are never shadowed by the {booking} wildcard.
    Route::get('/bookings/available-dates', [\App\Http\Controllers\Agency\BookingController::class, 'availableDates'])->name('bookings.available-dates');
    Route::get('/bookings/credit-status', [\App\Http\Controllers\Agency\BookingController::class, 'creditStatus'])->name('bookings.credit-status');
    Route::post('/bookings/temporary-hold', [\App\Http\Controllers\SvpHoldController::class, 'store'])->name('bookings.temporary-hold');
    Route::get('/bookings/lookup/cities', [\App\Http\Controllers\Agency\BookingController::class, 'lookupCities'])->name('bookings.lookup.cities');
    Route::get('/bookings/lookup/categories', [\App\Http\Controllers\Agency\BookingController::class, 'lookupCategories'])->name('bookings.lookup.categories');
    Route::get('/bookings/lookup/occupations', [\App\Http\Controllers\Agency\BookingController::class, 'lookupOccupations'])->name('bookings.lookup.occupations');
    Route::get('/bookings/lookup/test-centers', [\App\Http\Controllers\Agency\BookingController::class, 'lookupTestCenters'])->name('bookings.lookup.test-centers');
    Route::get('/bookings/lookup/sessions', [\App\Http\Controllers\Agency\BookingController::class, 'lookupSessions'])->name('bookings.lookup.sessions');
    Route::get('/bookings/lookup/verify-session-center', [SvpSessionVerificationController::class, 'show'])->name('bookings.lookup.verify-session-center');
    Route::post('/bookings/lookup/verify-session-center', [SvpSessionVerificationController::class, 'verify'])->name('bookings.lookup.verify-session-center.post');
    Route::get('/bookings/{booking}/payment', [\App\Http\Controllers\Agency\BookingController::class, 'payment'])->whereNumber('booking')->name('bookings.payment');
    Route::get('/bookings/{booking}/payment-return', [\App\Http\Controllers\Agency\BookingController::class, 'paymentReturn'])->whereNumber('booking')->name('bookings.payment-return');
    Route::post('/bookings/{booking}/verify-reservation', [\App\Http\Controllers\Agency\BookingController::class, 'verifyReservation'])->whereNumber('booking')->name('bookings.verify-reservation');
    Route::get('/bookings/{booking}',   [\App\Http\Controllers\Agency\BookingController::class, 'show'])->whereNumber('booking')->name('bookings.show');
    Route::post('/bookings/{booking}/cancel', [\App\Http\Controllers\Agency\BookingController::class, 'cancel'])->whereNumber('booking')->name('bookings.cancel');

    // Users
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [AgencyUserController::class, 'index'])->name('index');
        Route::get('/create', [AgencyUserController::class, 'create'])->name('create');
        Route::post('/', [AgencyUserController::class, 'store'])->name('store');
        Route::get('/{user}/edit', [AgencyUserController::class, 'edit'])->name('edit');
        Route::put('/{user}', [AgencyUserController::class, 'update'])->name('update');
        Route::post('/{user}/disable', [AgencyUserController::class, 'disable'])->name('disable');
        Route::post('/{user}/reset-password', [AgencyUserController::class, 'resetPassword'])->name('reset-password');
    });

    // Wallet
    Route::prefix('wallets')->name('wallets.')->group(function () {
        Route::get('/', [AgencyWalletController::class, 'index'])->name('index');
        Route::get('/ledger', [AgencyWalletController::class, 'ledger'])->name('ledger');
    });

    // Deposits
    Route::prefix('deposits')->name('deposits.')->group(function () {
        Route::get('/', [AgencyDepositController::class, 'index'])->name('index');
        Route::get('/create', [AgencyDepositController::class, 'create'])->name('create');
        Route::post('/', [AgencyDepositController::class, 'store'])->name('store');
    });

    // Refunds
    Route::prefix('refunds')->name('refunds.')->group(function () {
        Route::get('/', [AgencyRefundController::class, 'index'])->name('index');
        Route::get('/create', [AgencyRefundController::class, 'create'])->name('create');
        Route::post('/', [AgencyRefundController::class, 'store'])->name('store');
    });

    // Reports
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/daily-bookings', [AgencyReportController::class, 'dailyBookings'])->name('daily-bookings');
        Route::get('/wallet-statement', [AgencyReportController::class, 'walletStatement'])->name('wallet-statement');
        Route::get('/user-activity', [AgencyReportController::class, 'userActivity'])->name('user-activity');
        Route::get('/failed-bookings', [AgencyReportController::class, 'failedBookings'])->name('failed-bookings');
        Route::get('/deposit-history', [AgencyReportController::class, 'depositHistory'])->name('deposit-history');
    });

    // Notifications
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [AgencyNotificationController::class, 'index'])->name('index');
        Route::post('/{notification}/mark-read', [AgencyNotificationController::class, 'markRead'])->name('mark-read');
    });
});

// -------------------------------
// User panel
// -------------------------------
Route::middleware('web')->prefix('user')->name('user.')->middleware(['auth.multi'])->group(function () {
    Route::get('/dashboard', [UserDashboardController::class, 'index'])->name('dashboard');

    // Bookings
    Route::prefix('bookings')->name('bookings.')->group(function () {
        Route::get('/', [UserBookingController::class, 'index'])->name('index');
        Route::get('/create', [UserBookingController::class, 'create'])->name('create');
        Route::post('/', [UserBookingController::class, 'store'])->name('store');
        Route::get('/available-dates', [UserBookingController::class, 'availableDates'])->name('available-dates');
        Route::get('/credit-status', [UserBookingController::class, 'creditStatus'])->name('credit-status');
        Route::post('/temporary-hold', [\App\Http\Controllers\SvpHoldController::class, 'store'])->name('temporary-hold');
        Route::get('/lookup/cities', [UserBookingController::class, 'lookupCities'])->name('lookup.cities');
        Route::get('/lookup/categories', [UserBookingController::class, 'lookupCategories'])->name('lookup.categories');
        Route::get('/lookup/occupations', [UserBookingController::class, 'lookupOccupations'])->name('lookup.occupations');
        Route::get('/lookup/test-centers', [UserBookingController::class, 'lookupTestCenters'])->name('lookup.test-centers');
        Route::get('/lookup/sessions', [UserBookingController::class, 'lookupSessions'])->name('lookup.sessions');
        Route::get('/lookup/verify-session-center', [SvpSessionVerificationController::class, 'show'])->name('lookup.verify-session-center');
        Route::post('/lookup/verify-session-center', [SvpSessionVerificationController::class, 'verify'])->name('lookup.verify-session-center.post');
        Route::get('/svp-reservations/{reservation}/ticket', [UserBookingController::class, 'svpTicket'])
            ->whereNumber('reservation')
            ->name('svp-ticket');
        Route::post('/svp-reservations/{reservation}/cancel', [UserBookingController::class, 'svpCancel'])
            ->whereNumber('reservation')
            ->name('svp-cancel');
        Route::get('/svp-reservations/{reservation}/reschedule', [UserBookingController::class, 'svpReschedule'])
            ->whereNumber('reservation')
            ->name('svp-reschedule');
        Route::post('/svp-reservations/{reservation}/reschedule', [UserBookingController::class, 'svpRescheduleSubmit'])
            ->whereNumber('reservation')
            ->name('svp-reschedule.submit');
        Route::get('/{booking}/payment', [UserBookingController::class, 'payment'])->whereNumber('booking')->name('payment');
        Route::get('/{booking}/payment-return', [UserBookingController::class, 'paymentReturn'])->whereNumber('booking')->name('payment-return');
        Route::post('/{booking}/verify-reservation', [UserBookingController::class, 'verifyReservation'])->whereNumber('booking')->name('verify-reservation');
        Route::get('/{booking}', [UserBookingController::class, 'show'])->whereNumber('booking')->name('show');
    });

    // Wallet
    Route::prefix('wallets')->name('wallets.')->group(function () {
        Route::get('/', [UserWalletController::class, 'index'])->name('index');
    });

    // Deposits
    Route::prefix('deposits')->name('deposits.')->group(function () {
        Route::get('/', [UserDepositController::class, 'index'])->name('index');
        Route::get('/create', [UserDepositController::class, 'create'])->name('create');
        Route::post('/', [UserDepositController::class, 'store'])->name('store');
    });

    // Refunds
    Route::prefix('refunds')->name('refunds.')->group(function () {
        Route::get('/', [UserRefundController::class, 'index'])->name('index');
        Route::get('/create', [UserRefundController::class, 'create'])->name('create');
        Route::post('/', [UserRefundController::class, 'store'])->name('store');
    });

    // Notifications
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [UserNotificationController::class, 'index'])->name('index');
        Route::post('/{notification}/mark-read', [UserNotificationController::class, 'markRead'])->name('mark-read');
        Route::post('/mark-all-read', [UserNotificationController::class, 'markAllRead'])->name('mark-all-read');
    });
});
