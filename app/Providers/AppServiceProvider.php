<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        RateLimiter::for('portal-external-api', function (Request $request): Limit {
            $header = (string) config('portal.external_api.header', 'X-Portal-API-Key');
            $presentedKey = trim((string) $request->header($header));
            $bucket = $presentedKey !== ''
                ? 'key:'.hash('sha256', $presentedKey)
                : 'ip:'.($request->ip() ?? 'unknown');

            return Limit::perMinute(max(1, (int) config('portal.external_api.rate_limit_per_minute', 60)))
                ->by($bucket);
        });

        // Permission seeding removed — use database/seeders instead.
    }
}
