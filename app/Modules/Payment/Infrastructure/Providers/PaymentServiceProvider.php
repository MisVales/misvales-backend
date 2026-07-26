<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Providers;

use App\Modules\Payment\Application\Contracts\BankCoveragePort;
use App\Modules\Payment\Application\Contracts\BankFileContract;
use App\Modules\Payment\Application\Contracts\BankFolioScopePort;
use App\Modules\Payment\Application\Contracts\PaymentAuditPort;
use App\Modules\Payment\Application\Contracts\PaymentAuthorizationPort;
use App\Modules\Payment\Application\Contracts\PaymentClock;
use App\Modules\Payment\Application\Contracts\PaymentConfigurationPort;
use App\Modules\Payment\Application\Contracts\PaymentOutboxPort;
use App\Modules\Payment\Application\Contracts\PrivateMediaPort;
use App\Modules\Payment\Application\Contracts\RefundMethodContract;
use App\Modules\Payment\Application\Contracts\RelationPaymentPort;
use App\Modules\Payment\Infrastructure\Integrations\DatabasePaymentAudit;
use App\Modules\Payment\Infrastructure\Integrations\EloquentPaymentOutbox;
use App\Modules\Payment\Infrastructure\Integrations\UnavailableBankCoveragePort;
use App\Modules\Payment\Infrastructure\Integrations\UnavailableBankFileContract;
use App\Modules\Payment\Infrastructure\Integrations\UnavailableBankFolioScopePort;
use App\Modules\Payment\Infrastructure\Integrations\UnavailablePaymentAuthorizationPort;
use App\Modules\Payment\Infrastructure\Integrations\UnavailablePaymentConfigurationPort;
use App\Modules\Payment\Infrastructure\Integrations\UnavailablePrivateMediaPort;
use App\Modules\Payment\Infrastructure\Integrations\UnavailableRefundMethodContract;
use App\Modules\Payment\Infrastructure\Integrations\UnavailableRelationPaymentPort;
use App\Modules\Payment\Infrastructure\Time\SystemPaymentClock;
use Illuminate\Support\ServiceProvider;

/** Registra M11 y deniega por defecto toda dependencia funcional todavía no publicada. */
final class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BankFileContract::class, UnavailableBankFileContract::class);
        $this->app->bind(BankFolioScopePort::class, UnavailableBankFolioScopePort::class);
        $this->app->bind(RelationPaymentPort::class, UnavailableRelationPaymentPort::class);
        $this->app->bind(PrivateMediaPort::class, UnavailablePrivateMediaPort::class);
        $this->app->bind(PaymentAuthorizationPort::class, UnavailablePaymentAuthorizationPort::class);
        $this->app->bind(PaymentConfigurationPort::class, UnavailablePaymentConfigurationPort::class);
        $this->app->bind(BankCoveragePort::class, UnavailableBankCoveragePort::class);
        $this->app->bind(RefundMethodContract::class, UnavailableRefundMethodContract::class);
        $this->app->bind(PaymentAuditPort::class, DatabasePaymentAudit::class);
        $this->app->bind(PaymentOutboxPort::class, EloquentPaymentOutbox::class);
        $this->app->bind(PaymentClock::class, SystemPaymentClock::class);
    }
}
