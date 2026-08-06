<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\ProfileService;

class ProfileController
{
    /**
     * @param  ProfileService  $profile
     */
    public function __construct(private ProfileService $profile) {}

    public function profile(Request $request): JsonResponse
    {
        return $this->profile->profile($this->svpToken($request));
    }

    public function permissions(Request $request): JsonResponse
    {
        return $this->profile->permissions($this->svpToken($request));
    }

    public function certificatePrice(Request $request): JsonResponse
    {
        return $this->profile->certificatePrice($this->svpToken($request));
    }

    public function featureFlags(Request $request): JsonResponse
    {
        return $this->profile->featureFlags($this->svpToken($request));
    }

    public function userBalance(Request $request, string $user): JsonResponse
    {
        return $this->profile->userBalance($this->svpToken($request), $user);
    }

    private function svpToken(Request $request): string
    {
        $bearer = $request->bearerToken();

        return is_string($bearer) ? $bearer : '';
    }
}
