<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Infrastructure\Providers;

use App\Modules\Client\Application\Contracts\AuthorizedMobilityPort;
use App\Modules\Mobility\Application\Contracts\ClientMobilityPort;
use App\Modules\Mobility\Application\Contracts\MobilityReauthenticationPort;
use App\Modules\Mobility\Application\Contracts\MobilityRecorder;
use App\Modules\Mobility\Application\Contracts\OrganizationMobilityPort;
use App\Modules\Mobility\Infrastructure\Integrations\AccessMobilityReauthentication;
use App\Modules\Mobility\Infrastructure\Integrations\CompletedMobilityAuthorization;
use App\Modules\Mobility\Infrastructure\Integrations\EloquentClientMobility;
use App\Modules\Mobility\Infrastructure\Integrations\UnavailableOrganizationMobility;
use App\Modules\Mobility\Infrastructure\Persistence\DatabaseMobilityRecorder;
use App\Modules\Mobility\Infrastructure\Persistence\Models\AdministrativeReassignment;
use App\Modules\Mobility\Infrastructure\Persistence\Models\ClientTransfer;
use App\Modules\Mobility\Infrastructure\Persistence\Models\CoordinatorReassignmentBatch;
use App\Modules\Mobility\Infrastructure\Persistence\Models\DistributorBranchChange;
use App\Modules\Mobility\Presentation\Http\Policies\ClientTransferPolicy;
use App\Modules\Mobility\Presentation\Http\Policies\ManagerMobilityPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class MobilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ClientMobilityPort::class, EloquentClientMobility::class);
        $this->app->bind(OrganizationMobilityPort::class, UnavailableOrganizationMobility::class);
        $this->app->bind(MobilityReauthenticationPort::class, AccessMobilityReauthentication::class);
        $this->app->bind(MobilityRecorder::class, DatabaseMobilityRecorder::class);
        $this->app->bind(AuthorizedMobilityPort::class, CompletedMobilityAuthorization::class);
    }

    public function boot(): void
    {
        Gate::policy(ClientTransfer::class, ClientTransferPolicy::class);
        Gate::policy(AdministrativeReassignment::class, ManagerMobilityPolicy::class);
        Gate::policy(DistributorBranchChange::class, ManagerMobilityPolicy::class);
        Gate::policy(CoordinatorReassignmentBatch::class, ManagerMobilityPolicy::class);
        RateLimiter::for('mobility-sensitive', static function (Request $request): Limit {
            $actor = $request->user();
            $key = $actor === null ? 'ip:'.hash('sha256', (string) $request->ip()) : 'user:'.$actor->getAuthIdentifier();

            return Limit::perMinute(10)->by($key);
        });
    }
}
