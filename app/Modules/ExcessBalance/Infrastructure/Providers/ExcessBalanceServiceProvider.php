<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Infrastructure\Providers;

use App\Modules\ExcessBalance\Application\Contracts\CreditBalanceApplicationPort;
use App\Modules\ExcessBalance\Application\Contracts\DetectedExcessRegistrar;
use App\Modules\ExcessBalance\Application\Contracts\ExcessEventPublisher;
use App\Modules\ExcessBalance\Application\Contracts\ExcessOutboxTransport;
use App\Modules\ExcessBalance\Application\Contracts\ExcessReauthenticationPort;
use App\Modules\ExcessBalance\Application\Contracts\PrivateEvidencePort;
use App\Modules\ExcessBalance\Application\Contracts\RefundExecutionPolicy;
use App\Modules\ExcessBalance\Application\Services\RegisterDetectedExcess;
use App\Modules\ExcessBalance\Domain\Policies\ExcessBalancePolicy;
use App\Modules\ExcessBalance\Domain\Policies\RefundRequestPolicy;
use App\Modules\ExcessBalance\Infrastructure\Integrations\AccessExcessReauthentication;
use App\Modules\ExcessBalance\Infrastructure\Integrations\SharedOutboxExcessEventPublisher;
use App\Modules\ExcessBalance\Infrastructure\Integrations\UnavailableCreditBalanceApplicationPort;
use App\Modules\ExcessBalance\Infrastructure\Integrations\UnavailableExcessOutboxTransport;
use App\Modules\ExcessBalance\Infrastructure\Integrations\UnavailablePrivateEvidencePort;
use App\Modules\ExcessBalance\Infrastructure\Integrations\UnavailableRefundExecutionPolicy;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\ExcessBalanceModel;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\RefundRequestModel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class ExcessBalanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DetectedExcessRegistrar::class, RegisterDetectedExcess::class);
        $this->app->bind(CreditBalanceApplicationPort::class, UnavailableCreditBalanceApplicationPort::class);
        $this->app->bind(RefundExecutionPolicy::class, UnavailableRefundExecutionPolicy::class);
        $this->app->bind(PrivateEvidencePort::class, UnavailablePrivateEvidencePort::class);
        $this->app->bind(ExcessReauthenticationPort::class, AccessExcessReauthentication::class);
        $this->app->bind(ExcessEventPublisher::class, SharedOutboxExcessEventPublisher::class);
        $this->app->bind(ExcessOutboxTransport::class, UnavailableExcessOutboxTransport::class);
    }

    public function boot(): void
    {
        Gate::policy(ExcessBalanceModel::class, ExcessBalancePolicy::class);
        Gate::policy(RefundRequestModel::class, RefundRequestPolicy::class);
    }
}
