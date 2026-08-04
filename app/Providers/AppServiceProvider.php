<?php

namespace App\Providers;

use App\Modules\Organization\Application\Assignments\Identity\OrganizationIdentityAccess;
use App\Modules\Organization\Application\Assignments\Repositories\AssignmentReadRepository;
use App\Modules\Organization\Application\Branches\Repositories\BranchReadRepository;
use App\Modules\Organization\Application\Events\OrganizationEventPublisher;
use App\Modules\Organization\Application\Personnel\Repositories\PersonnelReadRepository;
use App\Modules\Organization\Domain\Assignments\Repositories\OrganizationAssignmentRepository;
use App\Modules\Organization\Domain\Assignments\Services\OrganizationHierarchyResolver;
use App\Modules\Organization\Domain\Assignments\Services\OrganizationScopeResolver;
use App\Modules\Organization\Domain\Branches\Repositories\BranchRepository;
use App\Modules\Organization\Infrastructure\Events\DatabaseOrganizationEventPublisher;
use App\Modules\Organization\Infrastructure\IdentityAccess\EloquentOrganizationHierarchyResolver;
use App\Modules\Organization\Infrastructure\IdentityAccess\EloquentOrganizationIdentityAccess;
use App\Modules\Organization\Infrastructure\IdentityAccess\EloquentOrganizationScopeResolver;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\EloquentAssignmentReadRepository;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\EloquentBranchReadRepository;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\EloquentBranchRepository;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\EloquentOrganizationAssignmentRepository;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\EloquentPersonnelReadRepository;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use App\Policies\BranchPolicy;
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
        $this->app->bind(AssignmentReadRepository::class, EloquentAssignmentReadRepository::class);
        $this->app->bind(OrganizationEventPublisher::class, DatabaseOrganizationEventPublisher::class);
        $this->app->bind(OrganizationIdentityAccess::class, EloquentOrganizationIdentityAccess::class);
        $this->app->bind(BranchRepository::class, EloquentBranchRepository::class);
        $this->app->bind(BranchReadRepository::class, EloquentBranchReadRepository::class);
        $this->app->bind(OrganizationScopeResolver::class, EloquentOrganizationScopeResolver::class);
        $this->app->bind(OrganizationHierarchyResolver::class, EloquentOrganizationHierarchyResolver::class);
        $this->app->bind(OrganizationAssignmentRepository::class, EloquentOrganizationAssignmentRepository::class);
        $this->app->bind(PersonnelReadRepository::class, EloquentPersonnelReadRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(BranchRecord::class, BranchPolicy::class);

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
