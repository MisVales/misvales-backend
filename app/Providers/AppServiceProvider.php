<?php

namespace App\Providers;

use App\Modules\Access\Application\Accounts\DistributorAccessProvisioner;
use App\Modules\Access\Application\Authorization\PasskeyAssertionValidator;
use App\Modules\Access\Application\Authorization\WebAuthnPasskeyAssertionValidator;
use App\Modules\Access\Application\MFA\PasskeyAttestationVerifier;
use App\Modules\Access\Application\Security\LaravelSecurityNotificationSender;
use App\Modules\Access\Application\Security\SecurityNotificationSender;
use App\Modules\Access\Application\Services\BranchScopeService;
use App\Modules\Access\Application\Services\OrganizationContextInvalidatorService;
use App\Modules\Access\Application\Services\OrganizationContextService;
use App\Modules\Access\Application\Services\RolePermissionService;
use App\Modules\Access\Domain\Accounts\DistributorFinalAuthorizationCompleted;
use App\Modules\Access\Domain\Contracts\BranchScopeCheckerInterface;
use App\Modules\Access\Domain\Contracts\OrganizationContextInvalidatorInterface;
use App\Modules\Access\Domain\Contracts\OrganizationContextProviderInterface;
use App\Modules\Access\Domain\Contracts\RolePermissionCheckerInterface;
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

        $this->app->bind(
            OrganizationContextInvalidatorInterface::class,
            OrganizationContextInvalidatorService::class
        );

        $this->app->bind(
            OrganizationContextProviderInterface::class,
            OrganizationContextService::class
        );

        $this->app->bind(
            BranchScopeCheckerInterface::class,
            BranchScopeService::class
        );

        $this->app->bind(
            RolePermissionCheckerInterface::class,
            RolePermissionService::class
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
