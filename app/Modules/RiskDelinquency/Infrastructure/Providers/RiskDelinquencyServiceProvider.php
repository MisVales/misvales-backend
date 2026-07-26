<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Providers;

use App\Modules\RiskDelinquency\Application\Contracts\CanDistributorIssueVoucher;
use App\Modules\RiskDelinquency\Application\Contracts\DistributorStatusPort;
use App\Modules\RiskDelinquency\Application\Contracts\OrganizationScopePort;
use App\Modules\RiskDelinquency\Application\Contracts\OverdueBalancePort;
use App\Modules\RiskDelinquency\Application\Contracts\RelationRiskSourcePort;
use App\Modules\RiskDelinquency\Application\Contracts\RiskAuditPort;
use App\Modules\RiskDelinquency\Application\Contracts\RiskClock;
use App\Modules\RiskDelinquency\Application\Contracts\RiskOutboxPort;
use App\Modules\RiskDelinquency\Application\Contracts\RiskReauthenticationPort;
use App\Modules\RiskDelinquency\Application\Services\DistributorVoucherBlock;
use App\Modules\RiskDelinquency\Application\Services\RiskRecorder;
use App\Modules\RiskDelinquency\Infrastructure\Integrations\AccessRiskReauthentication;
use App\Modules\RiskDelinquency\Infrastructure\Integrations\EloquentDistributorStatus;
use App\Modules\RiskDelinquency\Infrastructure\Integrations\EloquentOrganizationScope;
use App\Modules\RiskDelinquency\Infrastructure\Integrations\UnavailableOverdueBalance;
use App\Modules\RiskDelinquency\Infrastructure\Integrations\UnavailableRelationRiskSource;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DelinquencyRemovalRequest;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DistributorRiskProfile;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\RiskAlert;
use App\Modules\RiskDelinquency\Infrastructure\Time\SystemRiskClock;
use App\Modules\RiskDelinquency\Presentation\Http\Policies\DelinquencyRemovalRequestPolicy;
use App\Modules\RiskDelinquency\Presentation\Http\Policies\DistributorRiskProfilePolicy;
use App\Modules\RiskDelinquency\Presentation\Http\Policies\RiskAlertPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class RiskDelinquencyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RelationRiskSourcePort::class, UnavailableRelationRiskSource::class);
        $this->app->bind(OverdueBalancePort::class, UnavailableOverdueBalance::class);
        $this->app->bind(OrganizationScopePort::class, EloquentOrganizationScope::class);
        $this->app->bind(CanDistributorIssueVoucher::class, DistributorVoucherBlock::class);
        $this->app->bind(DistributorStatusPort::class, EloquentDistributorStatus::class);
        $this->app->bind(RiskReauthenticationPort::class, AccessRiskReauthentication::class);
        $this->app->bind(RiskClock::class, SystemRiskClock::class);
        $this->app->bind(RiskAuditPort::class, RiskRecorder::class);
        $this->app->bind(RiskOutboxPort::class, RiskRecorder::class);
    }

    public function boot(): void
    {
        Gate::policy(DistributorRiskProfile::class, DistributorRiskProfilePolicy::class);
        Gate::policy(RiskAlert::class, RiskAlertPolicy::class);
        Gate::policy(DelinquencyRemovalRequest::class, DelinquencyRemovalRequestPolicy::class);
        RateLimiter::for('risk-critical', static function (Request $request): Limit {
            $actor = $request->user();
            $key = $actor === null ? 'ip:'.hash('sha256', (string) $request->ip()) : 'user:'.$actor->getAuthIdentifier();

            return Limit::perMinute(5)->by($key);
        });
    }
}
