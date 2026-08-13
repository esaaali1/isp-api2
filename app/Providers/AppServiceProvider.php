<?php

namespace App\Providers;

use App\Services\MikrotikService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MikrotikService::class, fn () => new MikrotikService(
            timeout: (float) config('services.mikrotik.timeout', 3.0),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
