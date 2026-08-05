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
use App\Http\Controllers\User\BookingController as UserBookingController;
use App\Http\Controllers\User\DashboardController as UserDashboardController;
use App\Http\Controllers\User\DepositController as UserDepositController;
use App\Http\Controllers\User\NotificationController as UserNotificationController;
use App\Http\Controllers\User\RefundController as UserRefundController;
use App\Http\Controllers\User\WalletController as UserWalletController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('home');
})->middleware('web');

// -------------------------------
// Authentication
// -------------------------------
Route::middleware('web')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    // -------------------------------
    // SVP / Takamol real API login (email+password -> OTP -> bearer token)
    // -------------------------------
    Route::get('/svp/login', [SvpLoginController::class, 'showLoginForm'])->name('svp.login.form');
    Route::post('/svp/login', [SvpLoginController::class, 'login'])->name('svp.login.attempt');
    Route::get('/svp/otp', [SvpLoginController::class, 'showOtpForm'])->name('svp.otp.form');
    Route::post('/svp/otp/verify', [SvpLoginController::class, 'verifyOtp'])->name('svp.otp.verify');
    Route::post('/svp/otp/resend', [SvpLoginController::class, 'resendOtp'])->name('svp.otp.resend');

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
    Route::get('/bookings',             [\App\Http\Controllers\Agency\BookingController::class, 'index'])->name('bookings');
    Route::get('/bookings/create',      [\App\Http\Controllers\Agency\BookingController::class, 'create'])->name('bookings.create');
    Route::post('/bookings',            [\App\Http\Controllers\Agency\BookingController::class, 'store'])->name('bookings.store');
    Route::get('/bookings/{booking}',   [\App\Http\Controllers\Agency\BookingController::class, 'show'])->name('bookings.show');
    Route::get('/bookings/available-dates', [\App\Http\Controllers\Agency\BookingController::class, 'availableDates'])->name('bookings.available-dates');
    Route::post('/bookings/{booking}/cancel', [\App\Http\Controllers\Agency\BookingController::class, 'cancel'])->name('bookings.cancel');

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
        Route::get('/{booking}', [UserBookingController::class, 'show'])->name('show');
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
