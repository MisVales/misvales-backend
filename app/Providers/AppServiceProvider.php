<?php

namespace App\Providers;

use App\Modules\Access\Application\Accounts\DistributorAccessProvisioner;
use App\Modules\Access\Application\Authorization\PasskeyAssertionValidator;
use App\Modules\Access\Application\Authorization\WebAuthnPasskeyAssertionValidator;
use App\Modules\Access\Application\MFA\PasskeyAttestationVerifier;
use App\Modules\Access\Application\Security\LaravelSecurityNotificationSender;
use App\Modules\Access\Application\Security\SecurityNotificationSender;
use App\Modules\Access\Domain\Accounts\DistributorFinalAuthorizationCompleted;
use Illuminate\Support\Facades\Event;
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
        $this->app->singleton(
            PasskeyAttestationVerifier::class,
            fn (): PasskeyAttestationVerifier => new PasskeyAttestationVerifier(
                rpId: (string) config('access.webauthn.rp_id'),
                rpName: (string) config('app.name', 'MisVales'),
                origin: (string) config('access.webauthn.origin'),
            ),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(
            DistributorFinalAuthorizationCompleted::class,
            [DistributorAccessProvisioner::class, 'handle'],
        );
    }
}
