<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(function () {
            return Password::min(5);
        });

        // Public registration is unauthenticated, so it gets its own strict
        // limiter (per IP) instead of the general API throttle of 120/min.
        RateLimiter::for('public-registration', function (Request $request) {
            $max = max(1, (int) config('internship.registration.max_submissions_per_ip', 5));
            $minutes = max(1, (int) config('internship.registration.submission_window_minutes', 10));

            return Limit::perMinutes($minutes, $max)->by('public-registration:'.$request->ip());
        });

        RateLimiter::for('public-registration-meta', function (Request $request) {
            return Limit::perMinute(60)->by('public-registration-meta:'.$request->ip());
        });
    }
}
