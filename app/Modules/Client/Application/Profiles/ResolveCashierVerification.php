<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Profiles;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Client\Application\Contracts\CashierVoucherAccessPort;
use App\Modules\Client\Application\Contracts\ClientAuditPort;
use App\Modules\Client\Application\Contracts\ClientCashierVerificationData;
use App\Modules\Client\Application\Contracts\DistributorProfilePort;
use App\Modules\Client\Application\Contracts\ResolveClientForCashierVerification;
use App\Modules\Client\Application\Security\ClientActorContext;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Client\Domain\Security\SensitiveDataProtector;
use App\Modules\Client\Persistence\Models\Client;

/** Resuelve la PII mínima solo después de validar el vale atendible de M08. */
final readonly class ResolveCashierVerification implements ResolveClientForCashierVerification
{
    public function __construct(
        private CashierVoucherAccessPort $voucherAccess,
        private DistributorProfilePort $profiles,
        private SensitiveDataProtector $protector,
        private ClientAuditPort $audit,
    ) {}

    public function handle(
        string $clientId,
        string $voucherId,
        ClientActorContext $cashier,
        string $requestId,
    ): ClientCashierVerificationData {
        if (
            $cashier->role !== RoleCode::CASHIER
            || $cashier->branchId === null
            || ! $cashier->hasPermission(PermissionCode::CLIENTS_VIEW_SENSITIVE_AUTHORIZED->value)
            || ! $cashier->hasPermission(PermissionCode::CLIENTS_VIEW_DOCUMENTS_AUTHORIZED->value)
        ) {
            throw ClientDomainException::authorizationDenied();
        }
        $client = Client::query()
            ->with(['currentAssignment', 'currentAddress', 'currentBankAccount', 'currentDocuments'])
            ->whereKey($clientId)
            ->first();
        if (
            $client === null
            || $client->currentAssignment === null
            || $client->currentAddress === null
            || $client->currentBankAccount === null
        ) {
            throw ClientDomainException::notFoundOrOutOfScope();
        }
        $profile = $this->profiles->activeById($client->currentAssignment->distributor_id);
        if ($profile->branchId !== $cashier->branchId) {
            throw ClientDomainException::notFoundOrOutOfScope();
        }
        $this->voucherAccess->assertAttendable($voucherId, $clientId, $cashier->userId, $cashier->branchId);

        $address = $client->currentAddress;
        $data = new ClientCashierVerificationData(
            clientId: $client->id,
            displayName: trim($client->given_names.' '.$client->surnames),
            address: [
                'street' => $this->protector->decrypt($address->street_ciphertext),
                'exterior_number' => $this->protector->decrypt($address->exterior_number_ciphertext),
                'interior_number' => $address->interior_number_ciphertext === null
                    ? null
                    : $this->protector->decrypt($address->interior_number_ciphertext),
                'neighborhood' => $this->protector->decrypt($address->neighborhood_ciphertext),
                'postal_code' => $this->protector->decrypt($address->postal_code_ciphertext),
                'municipality' => $this->protector->decrypt($address->municipality_ciphertext),
                'city' => $this->protector->decrypt($address->city_ciphertext),
                'state' => $this->protector->decrypt($address->state_ciphertext),
            ],
            bankAccount: $this->protector->decrypt($client->currentBankAccount->account_ciphertext),
            documents: $client->currentDocuments->map(static fn ($document): array => [
                'type' => $document->document_type->value,
                'private_reference' => $document->media_id,
            ])->values()->all(),
        );
        $this->audit->record(
            'CLIENT_CASHIER_VERIFICATION_VIEWED',
            $client->id,
            $cashier,
            $profile->distributorId,
            $voucherId,
            ['identity', 'address', 'bank_account', 'documents'],
            'SUCCESS',
            $requestId,
        );

        return $data;
    }
}
