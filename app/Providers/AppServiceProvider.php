<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use App\Contracts\PortalAvailabilityProviderInterface;
use App\Services\Providers\BookingProviderInterface;
use App\Services\Providers\PortalAvailabilityProvider;
use App\Services\Providers\TakamolProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind the official SVP/Takamol provider to the booking interface.
        $this->app->bind(BookingProviderInterface::class, TakamolProvider::class);

        // Keep the portal availability adapter separate: it is read-only and
        // never becomes the provider for booking, payment, or reservations.
        $this->app->bind(PortalAvailabilityProviderInterface::class, PortalAvailabilityProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Belt-and-suspenders alongside trustProxies(): always generate
        // https:// URLs in production so routes/forms/AJAX calls never
        // resolve to http:// behind Railway's TLS-terminating proxy.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Permission seeding removed — use database/seeders instead.
    }
}
