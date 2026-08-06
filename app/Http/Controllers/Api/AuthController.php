<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\AuthService;

class AuthController
{
    public function __construct(private AuthService $auth) {}

    /**
     * POST /api/v1/sessions/login
     *
     * Accepts a payload (e.g. username/identifier + password), forwards it to
     * the SVP login endpoint, and returns the response unchanged.
     */
    public function login(Request $request): JsonResponse
    {
        return $this->auth->login($this->userPayload($request));
    }

    /**
     * POST /api/v1/sessions/otp
     *
     * Accepts the OTP attempt (plus login credentials) and forwards it to the
     * SVP OTP endpoint, returning the response (typically containing the
     * access token and CSRF token).
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        return $this->auth->verifyOtp($this->userPayload($request, otp: true));
    }

    /**
     * Build the nested `user` object the SVP API documents for auth requests:
     *
     *   POST /sessions/login → {"user":{"login","password","otp_method","fe_app","recaptcha_response"}}
     *   POST /sessions/otp   → {"user":{"login","password","otp_attempt","fe_app","otp_method"}}
     *
     * Accepts either the fully-formed `user` object from the caller (forwarded
     * unchanged) or flat fields, and always emits the exact documented shape.
     */
    private function userPayload(Request $request, bool $otp = false): array
    {
        $user = $request->input('user');

        if (! is_array($user)) {
            $user = [
                'login'      => $request->input('login')
                    ?? $request->input('identifier')
                    ?? $request->input('email')
                    ?? $request->input('mobile_number'),
                'password'   => $request->input('password'),
                'otp_method' => $request->input('otp_method', 'email'),
                'fe_app'     => $request->input('fe_app', 'legislator'),
            ];

            if ($otp) {
                $user['otp_attempt'] = $request->input('otp_attempt')
                    ?? $request->input('otp_code')
                    ?? $request->input('code');
            } elseif ($request->filled('recaptcha_response')) {
                $user['recaptcha_response'] = $request->input('recaptcha_response');
            }
        }

        return ['user' => array_filter($user, static fn ($value) => $value !== null)];
    }
}
