<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\SvpOtp\SvpAutoSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function showLoginForm(Request $request)
    {
        // If user already has a valid SVP token, skip straight to the booking page.
        if ($request->session()->has(SvpAutoSession::sessionKey('token', $request))) {
            return redirect()->route('user.bookings.create');
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'login'    => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $field = filter_var($credentials['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $password = $credentials['password'];
        $remember = $request->boolean('remember');

        // Drop the previous user's scoped SVP session before logging out;
        // otherwise the authenticated identity needed to derive its scope is gone.
        app(SvpAutoSession::class)->forget($request);
        Auth::guard('web')->logout();
        Auth::guard('admin')->logout();

        // 1) Platform admin — match by email or display name.
        $admin = \App\Models\Admin::query()
            ->where('email', $credentials['login'])
            ->orWhere('name', $credentials['login'])
            ->first();

        if ($admin && Auth::guard('admin')->attempt(['id' => $admin->id, 'password' => $password], $remember)) {
            $request->session()->regenerate();

            return redirect()->intended(route('admin.dashboard'));
        }

        // 2) Agency / regular user.
        if (Auth::guard('web')->attempt([
            $field    => $credentials['login'],
            'password' => $password,
        ], $remember)) {
            $request->session()->regenerate();

            $user = Auth::guard('web')->user();

            if ($user && $user->agency_id !== null && $user->hasPermission('manage agency users')) {
                return redirect()->intended(route('agency.dashboard'));
            }

            // Regular agency members and standalone users use the user panel.
            // They can access their dashboard immediately; the booking
            // flow will prompt for SVP login only when they try to book.
            return redirect()->intended(route('user.dashboard'));
        }

        throw ValidationException::withMessages([
            'login' => __('These credentials do not match our records.'),
        ]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        Auth::guard('admin')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
