<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Providers\BookingProviderInterface;
use App\Services\Providers\TakamolProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind the SVP/Takamol API provider to the booking interface.
        $this->app->bind(BookingProviderInterface::class, TakamolProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Permission seeding removed — use database/seeders instead.
    }
}
