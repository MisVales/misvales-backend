<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        // Interceptor global de Autorización (Punto 8)
        Gate::before(function ($user, string $ability) {
            // El Super Admin (general_manager) o la comprobación dinámica por BD:
            if ($user->hasPermissionTo($ability)) {
                return true;
            }
        });
        // Límites de intentos (respaldados por Redis Cache)

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->input('email', $request->ip()));
        });

        RateLimiter::for('totp', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('recovery_code', function (Request $request) {
            return Limit::perMinute(3)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('inspect_invitation', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('forgot_password', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });

        RateLimiter::for('resend_invitation', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });
    }
}
