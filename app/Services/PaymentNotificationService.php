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

    public function notifications(string $token)
    {
        return $this->provider->withToken($token)->notifications();
    }

    public function verificationRequests(string $token)
    {
        return $this->provider->withToken($token)->verificationRequests();
    }
}
