<?php

namespace App\Services;

use App\Services\Providers\BookingProviderInterface;

class ProfileService
{
    /**
     * @param  BookingProviderInterface  $provider
     */
    public function __construct(private BookingProviderInterface $provider) {}

    public function profile(string $token)
    {
        return $this->provider->withToken($token)->profile();
    }

    public function permissions(string $token)
    {
        return $this->provider->withToken($token)->permissions();
    }

    public function certificatePrice(string $token)
    {
        return $this->provider->withToken($token)->certificatePrice();
    }

    public function featureFlags(string $token)
    {
        return $this->provider->withToken($token)->featureFlags();
    }

    public function userBalance(string $token, string $userId)
    {
        return $this->provider->withToken($token)->userBalance($userId);
    }
}
