<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\PaymentNotificationService;

class PaymentNotificationController
{
    /**
     * @param  PaymentNotificationService  $service
     */
    public function __construct(private PaymentNotificationService $service) {}

    public function validatePendingPayment(Request $request): JsonResponse
    {
        return $this->service->validatePendingPayment($this->svpToken($request));
    }

    public function notifications(Request $request): JsonResponse
    {
        return $this->service->notifications($this->svpToken($request));
    }

    public function verificationRequests(Request $request): JsonResponse
    {
        return $this->service->verificationRequests($this->svpToken($request));
    }

    private function svpToken(Request $request): string
    {
        $bearer = $request->bearerToken();

        return is_string($bearer) ? $bearer : '';
    }
}
