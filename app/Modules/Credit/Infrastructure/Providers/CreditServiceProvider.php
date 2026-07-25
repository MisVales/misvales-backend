<?php

declare(strict_types=1);

namespace App\Modules\Credit\Infrastructure\Providers;

use App\Modules\Access\Domain\Accounts\DistributorAccessProvisioned;
use App\Modules\Credit\Application\Contracts\CreditRecoveryGateway;
use App\Modules\Credit\Application\Contracts\CreditVoucherGateway;
use App\Modules\Credit\Application\Services\CreditLineOperationsService;
use App\Modules\Credit\Application\Services\ProvisionInitialCreditLine;
use App\Modules\Credit\Domain\Policies\CreditIncreaseRequestPolicy;
use App\Modules\Credit\Domain\Policies\CreditLinePolicy;
use App\Modules\Credit\Domain\Repositories\CreditLineRepository;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditIncreaseRequestModel;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineModel;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Repositories\EloquentCreditLineRepository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class CreditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CreditLineRepository::class, EloquentCreditLineRepository::class);
        $this->app->bind(CreditVoucherGateway::class, CreditLineOperationsService::class);
        $this->app->bind(CreditRecoveryGateway::class, CreditLineOperationsService::class);
    }

    public function boot(): void
    {
        Gate::policy(CreditLineModel::class, CreditLinePolicy::class);
        Gate::policy(CreditIncreaseRequestModel::class, CreditIncreaseRequestPolicy::class);
        Event::listen(DistributorAccessProvisioned::class, [ProvisionInitialCreditLine::class, 'handle']);
    }
}
