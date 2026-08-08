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
        $this->app->bind(\App\Modules\Organization\Application\Assignments\Repositories\AssignmentReadRepository::class, \App\Modules\Organization\Infrastructure\Persistence\Eloquent\EloquentAssignmentReadRepository::class);
        $this->app->bind(\App\Modules\Organization\Domain\Events\OrganizationEventPublisher::class, \App\Modules\Organization\Infrastructure\Events\DatabaseOrganizationEventPublisher::class);
        $this->app->bind(\App\Modules\Organization\Domain\Identity\OrganizationIdentityAccess::class, \App\Modules\Organization\Infrastructure\Identity\EloquentOrganizationIdentityAccess::class);
        $this->app->bind(\App\Modules\Organization\Domain\Branches\Repositories\BranchRepository::class, \App\Modules\Organization\Infrastructure\Persistence\Eloquent\EloquentBranchRepository::class);
        $this->app->bind(\App\Modules\Organization\Application\Branches\Repositories\BranchReadRepository::class, \App\Modules\Organization\Infrastructure\Persistence\Eloquent\EloquentBranchReadRepository::class);
        $this->app->bind(\App\Modules\Organization\Domain\Assignments\OrganizationScopeResolver::class, \App\Modules\Organization\Infrastructure\Assignments\EloquentOrganizationScopeResolver::class);
        $this->app->bind(\App\Modules\Organization\Domain\Assignments\OrganizationHierarchyResolver::class, \App\Modules\Organization\Infrastructure\Assignments\EloquentOrganizationHierarchyResolver::class);
        $this->app->bind(\App\Modules\Organization\Domain\Assignments\Repositories\OrganizationAssignmentRepository::class, \App\Modules\Organization\Infrastructure\Persistence\Eloquent\EloquentOrganizationAssignmentRepository::class);
        $this->app->bind(\App\Modules\Organization\Application\Personnel\Repositories\PersonnelReadRepository::class, \App\Modules\Organization\Infrastructure\Persistence\Eloquent\EloquentPersonnelReadRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(\App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord::class, \App\Policies\BranchPolicy::class);
        Gate::policy(\App\Models\SolicitudDistribuidora::class, \App\Policies\SolicitudDistribuidoraPolicy::class);

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
