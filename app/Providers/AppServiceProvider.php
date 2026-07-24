<?php

namespace App\Providers;

use App\Modules\Access\Application\Authorization\PasskeyAssertionValidator;
use App\Modules\Access\Application\Authorization\WebAuthnPasskeyAssertionValidator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PasskeyAssertionValidator::class, WebAuthnPasskeyAssertionValidator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
