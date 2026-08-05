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
        $payload = $request->only(['identifier', 'username', 'email', 'password', 'mobile_number']);

        return $this->auth->login($payload);
    }

    /**
     * POST /api/v1/sessions/otp
     *
     * Accepts the OTP identifier + code, forwards it to the SVP OTP endpoint,
     * and returns the response (typically containing the access token).
     */
    public function verifyOtp(Request $request): JsonResponse
    {
        $payload = $request->only(['identifier', 'otp_code', 'code', 'reference']);

        return $this->auth->verifyOtp($payload);
    }
}
