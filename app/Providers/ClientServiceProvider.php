<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Client\Application\Assignments\ApplyAuthorizedAssignment;
use App\Modules\Client\Application\AuthorizedChanges\ApplyAuthorizedChanges;
use App\Modules\Client\Application\Contracts\ApplyAuthorizedClientAssignment;
use App\Modules\Client\Application\Contracts\ApplyAuthorizedClientChanges;
use App\Modules\Client\Application\Contracts\AuthorizedChangePort;
use App\Modules\Client\Application\Contracts\AuthorizedMobilityPort;
use App\Modules\Client\Application\Contracts\CashierVoucherAccessPort;
use App\Modules\Client\Application\Contracts\ClientAuditPort;
use App\Modules\Client\Application\Contracts\ClientOutboxPort;
use App\Modules\Client\Application\Contracts\ConfirmedVoucherPort;
use App\Modules\Client\Application\Contracts\DistributorProfilePort;
use App\Modules\Client\Application\Contracts\DocumentReferencePort;
use App\Modules\Client\Application\Contracts\RecordClientVoucherReference;
use App\Modules\Client\Application\Contracts\ResolveClientForCashierVerification;
use App\Modules\Client\Application\Contracts\ResolveClientForVoucher;
use App\Modules\Client\Application\Contracts\ValidateClientPortfolioForTransfer;
use App\Modules\Client\Application\Portfolio\RecordVoucherReference;
use App\Modules\Client\Application\Portfolio\ValidatePortfolioForTransfer;
use App\Modules\Client\Application\Profiles\ResolveCashierVerification;
use App\Modules\Client\Application\Profiles\ResolveClientVoucherSelection;
use App\Modules\Client\Domain\Security\ExactMatchHmac;
use App\Modules\Client\Domain\Security\SensitiveDataProtector;
use App\Modules\Client\Persistence\Integrations\DatabaseClientAudit;
use App\Modules\Client\Persistence\Integrations\EloquentClientOutbox;
use App\Modules\Client\Persistence\Integrations\UnavailableAuthorizedChangePort;
use App\Modules\Client\Persistence\Integrations\UnavailableAuthorizedMobilityPort;
use App\Modules\Client\Persistence\Integrations\UnavailableCashierVoucherAccessPort;
use App\Modules\Client\Persistence\Integrations\UnavailableConfirmedVoucherPort;
use App\Modules\Client\Persistence\Integrations\UnavailableDistributorProfilePort;
use App\Modules\Client\Persistence\Integrations\UnavailableDocumentReferencePort;
use App\Modules\Client\Persistence\Models\Client;
use App\Modules\Client\Persistence\Security\ConfiguredExactMatchHmac;
use App\Modules\Client\Persistence\Security\LaravelSensitiveDataProtector;
use App\Modules\Client\Presentation\Http\Policies\ClientPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** Registra M06 y mantiene denegadas por defecto las integraciones aún ausentes. */
final class ClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SensitiveDataProtector::class, LaravelSensitiveDataProtector::class);
        $this->app->bind(ExactMatchHmac::class, ConfiguredExactMatchHmac::class);
        $this->app->bind(DistributorProfilePort::class, UnavailableDistributorProfilePort::class);
        $this->app->bind(DocumentReferencePort::class, UnavailableDocumentReferencePort::class);
        $this->app->bind(AuthorizedChangePort::class, UnavailableAuthorizedChangePort::class);
        $this->app->bind(AuthorizedMobilityPort::class, UnavailableAuthorizedMobilityPort::class);
        $this->app->bind(ConfirmedVoucherPort::class, UnavailableConfirmedVoucherPort::class);
        $this->app->bind(CashierVoucherAccessPort::class, UnavailableCashierVoucherAccessPort::class);
        $this->app->bind(ClientAuditPort::class, DatabaseClientAudit::class);
        $this->app->bind(ClientOutboxPort::class, EloquentClientOutbox::class);
        $this->app->bind(ValidateClientPortfolioForTransfer::class, ValidatePortfolioForTransfer::class);
        $this->app->bind(ApplyAuthorizedClientAssignment::class, ApplyAuthorizedAssignment::class);
        $this->app->bind(ApplyAuthorizedClientChanges::class, ApplyAuthorizedChanges::class);
        $this->app->bind(RecordClientVoucherReference::class, RecordVoucherReference::class);
        $this->app->bind(ResolveClientForVoucher::class, ResolveClientVoucherSelection::class);
        $this->app->bind(ResolveClientForCashierVerification::class, ResolveCashierVerification::class);
    }

    public function boot(): void
    {
        Gate::policy(Client::class, ClientPolicy::class);
    }
}
