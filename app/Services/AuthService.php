<?php

namespace App\Services;

use App\Services\Providers\BookingProviderInterface;

class AuthService
{
    /**
     * @param  BookingProviderInterface  $provider
     */
    public function __construct(private BookingProviderInterface $provider) {}

    /**
     * Forward login payload to the SVP API.
     *
     * @param  array<string, mixed>  $payload
     */
    public function login(array $payload)
    {
        return $this->provider->login($payload);
    }

    /**
     * Forward OTP verification payload to the SVP API.
     *
     * @param  array<string, mixed>  $payload
     */
    public function verifyOtp(array $payload)
    {
        return $this->provider->verifyOtp($payload);
    }
}
