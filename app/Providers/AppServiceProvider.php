<?php

namespace App\Providers;

use App\Contracts\Credito\VerificadorDisponibilidadCredito;
use App\Exceptions\ApiException;
use App\Models\Category;
use App\Models\CategoryVersion;
use App\Models\ConfigurationDefinition;
use App\Models\ConfigurationVersion;
use App\Models\CoordinatorDistributorAssignment;
use App\Models\Distribuidora;
use App\Models\Product;
use App\Models\ProductVersion;
use App\Models\SolicitudDistribuidora;
use App\Models\Vale;
use App\Models\VerificationVisit;
use App\Modules\Organization\Application\Assignments\Identity\OrganizationIdentityAccess;
use App\Modules\Organization\Application\Assignments\Repositories\AssignmentReadRepository;
use App\Modules\Organization\Application\Branches\AddressValidator;
use App\Modules\Organization\Application\Branches\Repositories\BranchReadRepository;
use App\Modules\Organization\Application\Events\OrganizationEventPublisher;
use App\Modules\Organization\Application\Personnel\Repositories\PersonnelReadRepository;
use App\Modules\Organization\Domain\Assignments\Repositories\OrganizationAssignmentRepository;
use App\Modules\Organization\Domain\Assignments\Services\OrganizationHierarchyResolver;
use App\Modules\Organization\Domain\Assignments\Services\OrganizationScopeResolver;
use App\Modules\Organization\Domain\Branches\Repositories\BranchRepository;
use App\Modules\Organization\Infrastructure\Events\DatabaseOrganizationEventPublisher;
use App\Modules\Organization\Infrastructure\GoogleMaps\GoogleMapsAddressValidator;
use App\Modules\Organization\Infrastructure\IdentityAccess\EloquentOrganizationHierarchyResolver;
use App\Modules\Organization\Infrastructure\IdentityAccess\EloquentOrganizationIdentityAccess;
use App\Modules\Organization\Infrastructure\IdentityAccess\EloquentOrganizationScopeResolver;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\EloquentAssignmentReadRepository;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\EloquentBranchReadRepository;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\EloquentBranchRepository;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\EloquentOrganizationAssignmentRepository;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\EloquentPersonnelReadRepository;
use App\Modules\Organization\Infrastructure\Persistence\Eloquent\Models\BranchRecord;
use App\Observers\VersionObserver;
use App\Policies\BranchPolicy;
use App\Policies\CoordinatorAssignmentPolicy;
use App\Policies\DistribuidoraPolicy;
use App\Policies\SolicitudDistribuidoraPolicy;
use App\Policies\ValePolicy;
use App\Services\Credito\GeneradorFolioIncremento;
use App\Services\Credito\ServicioEstadoIncremento;
use App\Services\Credito\ServicioVerificadorDisponibilidadCredito;
use App\Services\Distribuidora\AuditorDistribuidora;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
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
        $this->app->singleton(GeneradorFolioIncremento::class, function ($app) {
            return new GeneradorFolioIncremento;
        });

        $this->app->singleton(ServicioEstadoIncremento::class, function ($app) {
            return new ServicioEstadoIncremento;
        });

        $this->app->bind(
            VerificadorDisponibilidadCredito::class,
            ServicioVerificadorDisponibilidadCredito::class
        );

        $this->app->bind(AssignmentReadRepository::class, EloquentAssignmentReadRepository::class);
        $this->app->bind(OrganizationEventPublisher::class, DatabaseOrganizationEventPublisher::class);
        $this->app->bind(OrganizationIdentityAccess::class, EloquentOrganizationIdentityAccess::class);
        $this->app->bind(AddressValidator::class, GoogleMapsAddressValidator::class);
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
        Relation::morphMap([
            'verification_visit' => VerificationVisit::class,
        ]);
        Gate::policy(BranchRecord::class, BranchPolicy::class);
        Gate::policy(CoordinatorDistributorAssignment::class, CoordinatorAssignmentPolicy::class);
        Gate::policy(Distribuidora::class, DistribuidoraPolicy::class);
        Gate::policy(SolicitudDistribuidora::class, SolicitudDistribuidoraPolicy::class);
        Gate::policy(Vale::class, ValePolicy::class);

        ConfigurationDefinition::observe(VersionObserver::class);
        ConfigurationVersion::observe(VersionObserver::class);
        Category::observe(VersionObserver::class);
        CategoryVersion::observe(VersionObserver::class);
        Product::observe(VersionObserver::class);
        ProductVersion::observe(VersionObserver::class);

        // Interceptor global de Autorización (Punto 8)
        Gate::before(function ($user, string $ability) {
            // El Super Admin (general_manager) o la comprobación dinámica por BD:
            if ($user->hasPermissionTo($ability)) {
                return true;
            }
        });
        // Límites de intentos (respaldados por Redis Cache)

        $configuredLimit = static fn (Limit $limit): Limit => config('ratelimit.enabled', true)
            ? $limit
            : Limit::none();

        RateLimiter::for('login', function (Request $request) use ($configuredLimit) {
            return $configuredLimit(Limit::perMinute(5)->by($request->input('email', $request->ip())));
        });

        RateLimiter::for('totp', function (Request $request) use ($configuredLimit) {
            return $configuredLimit(Limit::perMinute(5)->by($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('recovery_code', function (Request $request) use ($configuredLimit) {
            return $configuredLimit(Limit::perMinute(3)->by($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('inspect_invitation', function (Request $request) use ($configuredLimit) {
            return $configuredLimit(Limit::perMinute(10)->by($request->ip()));
        });

        RateLimiter::for('forgot_password', function (Request $request) use ($configuredLimit) {
            return $configuredLimit(Limit::perMinute(3)->by($request->ip()));
        });

        RateLimiter::for('reset_password', function (Request $request) use ($configuredLimit) {
            return $configuredLimit(Limit::perMinute(5)->by($request->input('email', $request->ip())));
        });

        RateLimiter::for('resend_invitation', function (Request $request) use ($configuredLimit) {
            $distribuidora = $request->route('distributor');
            $identifier = $distribuidora instanceof Distribuidora
                ? $distribuidora->id
                : (is_string($distribuidora) ? $distribuidora : $request->ip());

            return $configuredLimit(Limit::perMinute(3)->by($identifier)->response(function () use ($request, $distribuidora) {
                $affected = $distribuidora instanceof Distribuidora
                    ? $distribuidora
                    : Distribuidora::query()->find(is_string($distribuidora) ? $distribuidora : null);

                if ($affected !== null && $request->user() !== null) {
                    app(AuditorDistribuidora::class)->registrar(
                        'DISTRIBUTOR_ACTIVATION_INVITATION_RESENT',
                        'Distributor',
                        $affected->id,
                        $request->user(),
                        $affected->branch_id,
                        resultado: 'FAILED',
                        motivo: 'DISTRIBUTOR_INVITATION_RATE_LIMITED',
                    );
                }

                throw new ApiException('DISTRIBUTOR_INVITATION_RATE_LIMITED', 'Se alcanzó el límite de reenvíos de invitación.', 429);
            }));
        });
    }
}
