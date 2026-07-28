<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Providers;

use App\Modules\Client\Application\Contracts\AuthorizedChangePort;
use App\Modules\Client\Application\Contracts\CashierVoucherAccessPort;
use App\Modules\Client\Application\Contracts\ConfirmedVoucherPort;
use App\Modules\Client\Application\Contracts\DistributorProfilePort;
use App\Modules\Voucher\Application\Contracts\ClientVoucherGateway;
use App\Modules\Voucher\Application\Contracts\CreditBalanceSnapshotPort;
use App\Modules\Voucher\Application\Contracts\DistributorVoucherGateway;
use App\Modules\Voucher\Application\Contracts\ModificationRequestRepository;
use App\Modules\Voucher\Application\Contracts\VoucherConfigurationGateway;
use App\Modules\Voucher\Application\Contracts\VoucherEligibilityPort;
use App\Modules\Voucher\Application\Contracts\VoucherGenerationRepository;
use App\Modules\Voucher\Application\Contracts\VoucherRepository;
use App\Modules\Voucher\Application\Services\VerifiedModificationContext;
use App\Modules\Voucher\Infrastructure\Integrations\CreditBalanceSnapshotAdapter;
use App\Modules\Voucher\Infrastructure\Integrations\EloquentClientVoucherGateway;
use App\Modules\Voucher\Infrastructure\Integrations\EloquentDistributorProfiles;
use App\Modules\Voucher\Infrastructure\Integrations\EloquentDistributorVoucherGateway;
use App\Modules\Voucher\Infrastructure\Integrations\EloquentVoucherConfigurationGateway;
use App\Modules\Voucher\Infrastructure\Integrations\EloquentVoucherEligibility;
use App\Modules\Voucher\Infrastructure\Integrations\VoucherAuthorizedChangePort;
use App\Modules\Voucher\Infrastructure\Integrations\VoucherCashierAccessPort;
use App\Modules\Voucher\Infrastructure\Integrations\VoucherConfirmedPort;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\DataChangeRequestModel;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherModel;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Repositories\EloquentModificationRequestRepository;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Repositories\EloquentVoucherGenerationRepository;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Repositories\EloquentVoucherRepository;
use App\Modules\Voucher\Presentation\Http\Policies\DataChangeRequestPolicy;
use App\Modules\Voucher\Presentation\Http\Policies\VoucherPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/** Registra generación M08 y el flujo de caja M09 sobre el mismo agregado. */
final class VoucherServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VoucherRepository::class, EloquentVoucherRepository::class);
        $this->app->bind(VoucherGenerationRepository::class, EloquentVoucherGenerationRepository::class);
        $this->app->bind(ClientVoucherGateway::class, EloquentClientVoucherGateway::class);
        $this->app->bind(DistributorVoucherGateway::class, EloquentDistributorVoucherGateway::class);
        $this->app->bind(VoucherConfigurationGateway::class, EloquentVoucherConfigurationGateway::class);
        $this->app->bind(DistributorProfilePort::class, EloquentDistributorProfiles::class);
        $this->app->bind(ModificationRequestRepository::class, EloquentModificationRequestRepository::class);
        $this->app->bind(VoucherEligibilityPort::class, EloquentVoucherEligibility::class);
        $this->app->bind(CreditBalanceSnapshotPort::class, CreditBalanceSnapshotAdapter::class);
        $this->app->scoped(VerifiedModificationContext::class);
        $this->app->bind(AuthorizedChangePort::class, VoucherAuthorizedChangePort::class);
        $this->app->bind(CashierVoucherAccessPort::class, VoucherCashierAccessPort::class);
        $this->app->bind(ConfirmedVoucherPort::class, VoucherConfirmedPort::class);
    }

    public function boot(): void
    {
        Gate::policy(VoucherModel::class, VoucherPolicy::class);
        Gate::policy(DataChangeRequestModel::class, DataChangeRequestPolicy::class);
        $this->limit('voucher-generate', 'generate_per_minute');
        $this->limit('voucher-open', 'open_per_minute');
        $this->limit('voucher-modification-request', 'modification_requests_per_minute');
        $this->limit('voucher-token-attempt', 'token_attempts_per_minute');
        $this->limit('voucher-authorization', 'authorizations_per_minute');
        $this->limit('voucher-fulfillment', 'fulfillments_per_minute');
    }

    private function limit(string $name, string $configuration): void
    {
        RateLimiter::for($name, static function (Request $request) use ($configuration): Limit {
            $actor = $request->user();
            $key = $actor === null
                ? 'ip:'.hash('sha256', (string) $request->ip())
                : 'user:'.$actor->getAuthIdentifier();

            return Limit::perMinute((int) config('voucher.rate_limits.'.$configuration, 10))->by($key);
        });
    }
}
