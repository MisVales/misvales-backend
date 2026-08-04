<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use Illuminate\Support\Facades\Gate;

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
        // Registrar Observers para Auditoría (Módulo 3)
        \App\Models\ConfigurationDefinition::observe(\App\Observers\VersionObserver::class);
        \App\Models\ConfigurationVersion::observe(\App\Observers\VersionObserver::class);
        \App\Models\Category::observe(\App\Observers\VersionObserver::class);
        \App\Models\CategoryVersion::observe(\App\Observers\VersionObserver::class);
        \App\Models\Product::observe(\App\Observers\VersionObserver::class);
        \App\Models\ProductVersion::observe(\App\Observers\VersionObserver::class);
        \App\Models\RedemptionPeriod::observe(\App\Observers\VersionObserver::class);

        // Interceptor global de Autorización (Punto 8)
        Gate::before(function ($user, string $ability) {
            // El Super Admin (general_manager) o la comprobación dinámica por BD:
            if ($user->hasPermissionTo($ability)) {
                return true;
            }
        });
        // Límites de intentos (respaldados por Redis Cache)
        
        \Illuminate\Support\Facades\RateLimiter::for('login', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by($request->input('email', $request->ip()));
        });

        \Illuminate\Support\Facades\RateLimiter::for('totp', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        \Illuminate\Support\Facades\RateLimiter::for('recovery_code', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(3)->by($request->user()?->id ?: $request->ip());
        });

        \Illuminate\Support\Facades\RateLimiter::for('inspect_invitation', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by($request->ip());
        });

        \Illuminate\Support\Facades\RateLimiter::for('forgot_password', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(3)->by($request->ip());
        });
        
        \Illuminate\Support\Facades\RateLimiter::for('resend_invitation', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(3)->by($request->ip());
        });
    }
}
