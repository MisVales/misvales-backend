<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Integrations;

use App\Modules\Client\Persistence\Models\ClientBankAccount;
use App\Modules\Client\Persistence\Models\ClientDistributorAssignment;
use App\Modules\Distributor\Persistence\Models\Distributor;
use App\Modules\Voucher\Application\Contracts\VoucherConfigurationGateway;
use App\Modules\Voucher\Application\Contracts\VoucherEligibilityPort;
use App\Modules\Voucher\Application\DTOs\VoucherEligibility;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherModel;
use Carbon\CarbonImmutable;

/** Revalidación propietaria de M08 que permite a M09 operar el vale generado. */
final readonly class EloquentVoucherEligibility implements VoucherEligibilityPort
{
    public function __construct(private VoucherConfigurationGateway $configuration) {}

    public function forRelease(VoucherModel $voucher): VoucherEligibility
    {
        $this->assertOperationalContext($voucher);
        $this->assertProductStillAvailable($voucher);

        return new VoucherEligibility($this->activeBankAccount($voucher), $voucher->distributor_user_id);
    }

    public function forFulfillment(VoucherModel $voucher): VoucherEligibility
    {
        $this->assertOperationalContext($voucher);
        $this->assertProductStillAvailable($voucher);

        return new VoucherEligibility($this->activeBankAccount($voucher), $voucher->distributor_user_id);
    }

    public function forRejection(VoucherModel $voucher): VoucherEligibility
    {
        $this->assertOperationalContext($voucher);
        $bankId = ClientBankAccount::query()
            ->where('client_id', $voucher->client_id)
            ->where('active_slot', true)
            ->value('id');

        return new VoucherEligibility(is_string($bankId) ? $bankId : '', $voucher->distributor_user_id);
    }

    private function assertOperationalContext(VoucherModel $voucher): void
    {
        $distributor = Distributor::query()
            ->whereKey($voucher->distributor_id)
            ->where('status', 'ACTIVE')
            ->where('user_id', fn ($query) => $query
                ->select('public_id')
                ->from('users')
                ->where('id', $voucher->distributor_user_id))
            ->first();
        $assigned = ClientDistributorAssignment::query()
            ->where('client_id', $voucher->client_id)
            ->where('distributor_id', $voucher->distributor_id)
            ->where('branch_id_snapshot', $voucher->branch_id)
            ->where('active_slot', true)
            ->exists();
        if ($distributor === null || ! $assigned) {
            throw VoucherDomainException::invalidTransition();
        }
    }

    private function assertProductStillAvailable(VoucherModel $voucher): void
    {
        $product = $this->configuration->product($voucher->product_id, CarbonImmutable::now('UTC'));
        if ($product->capital->databaseValue() !== $voucher->capital_amount) {
            throw VoucherDomainException::productUnavailable();
        }
    }

    private function activeBankAccount(VoucherModel $voucher): string
    {
        $id = ClientBankAccount::query()
            ->where('client_id', $voucher->client_id)
            ->where('active_slot', true)
            ->value('id');
        if (! is_string($id)) {
            throw VoucherDomainException::rule(
                'CLIENT_BANK_ACCOUNT_NOT_AVAILABLE',
                'El cliente no conserva una cuenta bancaria vigente.',
                409,
            );
        }

        return $id;
    }
}
