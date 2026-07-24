<?php

namespace App\Providers;

use App\Modules\Access\Application\Authorization\PasskeyAssertionValidator;
use App\Modules\Access\Application\Authorization\WebAuthnPasskeyAssertionValidator;
use App\Modules\Access\Application\Security\LaravelSecurityNotificationSender;
use App\Modules\Access\Application\Security\SecurityNotificationSender;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PasskeyAssertionValidator::class, WebAuthnPasskeyAssertionValidator::class);
        $this->app->bind(SecurityNotificationSender::class, LaravelSecurityNotificationSender::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
