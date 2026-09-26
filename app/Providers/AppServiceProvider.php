<?php

namespace App\Providers;

use App\Infrastructure\Payments\PaymentProvider;
use App\Infrastructure\Payments\UnreliableMockPaymentProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentProvider::class, UnreliableMockPaymentProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('financial', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }
}
