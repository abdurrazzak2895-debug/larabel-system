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

    public function payments(Request $request): JsonResponse
    {
        return $this->service->payments($this->svpToken($request));
    }

    public function showPayment(Request $request, string $payment): JsonResponse
    {
        return $this->service->payments($this->svpToken($request), $payment);
    }

    public function storePayment(Request $request): JsonResponse
    {
        return $this->service->createPayment($this->svpToken($request), $request->all());
    }

    public function updatePayment(Request $request, string $payment): JsonResponse
    {
        return $this->service->updatePayment($this->svpToken($request), $payment, $request->all());
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
