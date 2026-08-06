<?php

namespace App\Services;

use App\Services\Providers\BookingProviderInterface;

class PaymentNotificationService
{
    /**
     * @param  BookingProviderInterface  $provider
     */
    public function __construct(private BookingProviderInterface $provider) {}

    public function validatePendingPayment(string $token)
    {
        return $this->provider->withToken($token)->validatePendingPayment();
    }

    /**
     * List payments (id null) or fetch a single payment (id given).
     */
    public function payments(string $token, ?string $id = null)
    {
        return $this->provider->withToken($token)->payments($id);
    }

    public function createPayment(string $token, array $payload)
    {
        return $this->provider->withToken($token)->createPayment($payload);
    }

    public function updatePayment(string $token, string $id, array $payload)
    {
        return $this->provider->withToken($token)->updatePayment($id, $payload);
    }

    public function notifications(string $token)
    {
        return $this->provider->withToken($token)->notifications();
    }

    public function verificationRequests(string $token)
    {
        return $this->provider->withToken($token)->verificationRequests();
    }
}
