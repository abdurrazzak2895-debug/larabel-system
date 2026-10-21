<?php

namespace App\Providers;

use App\Contracts\PortalAvailabilityProviderInterface;
use App\Models\SvpApiConsumerKey;
use App\Models\TakamolApiConsumerKey;
use App\Services\Providers\BookingProviderInterface;
use App\Services\Providers\PortalAvailabilityProvider;
use App\Services\Providers\TakamolProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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

        RateLimiter::for('svp-external-api', function (Request $request): Limit {
            $presentedKey = trim((string) $request->header((string) config('svp.external_api.header', 'X-Portal-API-Key')));
            $key = $request->attributes->get('svp_api_consumer_key');
            $limit = $key instanceof SvpApiConsumerKey
                ? (int) $key->rate_limit_per_minute
                : (int) config('svp.external_api.rate_limit_per_minute', 60);
            $bucket = $presentedKey !== ''
                ? 'key:'.hash('sha256', $presentedKey)
                : 'ip:'.($request->ip() ?? 'unknown');

            return Limit::perMinute(max(1, $limit))->by($bucket);
        });

        RateLimiter::for('takamol-external-api', function (Request $request): Limit {
            $header = (string) config('takamol_api.header', 'X-Takamol-API-Key');
            $presentedKey = trim((string) $request->header($header));
            $key = $request->attributes->get('takamol_api_consumer_key');

            if (! $key instanceof TakamolApiConsumerKey && $presentedKey !== '' && strlen($presentedKey) <= 128) {
                $key = TakamolApiConsumerKey::query()
                    ->usable()
                    ->where('key_hash', TakamolApiConsumerKey::hashPlaintext($presentedKey))
                    ->first();
            }

            $limit = $key instanceof TakamolApiConsumerKey
                ? (int) $key->rate_limit_per_minute
                : (int) config('takamol_api.rate_limit_per_minute', 60);
            $bucket = $presentedKey !== ''
                ? 'key:'.hash('sha256', $presentedKey)
                : 'ip:'.($request->ip() ?? 'unknown');

            return Limit::perMinute(max(1, $limit))->by($bucket);
        });

        // Permission seeding removed — use database/seeders instead.
    }
}
