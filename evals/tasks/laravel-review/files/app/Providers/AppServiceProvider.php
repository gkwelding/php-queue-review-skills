<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('geocoding', fn (object $job) => Limit::perSecond(10));
        RateLimiter::for('mailchimp-api', fn (object $job) => Limit::perMinute(300));
    }
}
