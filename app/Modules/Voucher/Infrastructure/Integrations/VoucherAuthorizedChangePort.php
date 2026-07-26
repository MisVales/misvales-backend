<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Integrations;

use App\Modules\Client\Application\Contracts\AuthorizedChangePort;
use App\Modules\Client\Application\Contracts\AuthorizedClientChange;
use App\Modules\Client\Application\Contracts\ConsumedChangeAuthorization;
use App\Modules\Voucher\Application\Services\VerifiedModificationContext;
use App\Modules\Voucher\Domain\Enums\DataChangeRequestStatus;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\AuthorizationTokenModel;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\DataChangeRequestModel;

/** Consumo transaccional exacto publicado por M09 para M06. */
final readonly class VoucherAuthorizedChangePort implements AuthorizedChangePort
{
    public function __construct(private VerifiedModificationContext $verified) {}

    public function consume(AuthorizedClientChange $change): ConsumedChangeAuthorization
    {
        $tokenId = $this->verified->consume($change->authorizationId, $change->operationId);
        $request = DataChangeRequestModel::query()
            ->whereKey($change->authorizationId)
            ->lockForUpdate()
            ->first();
        $token = AuthorizationTokenModel::query()
            ->whereKey($tokenId)
            ->where('data_change_request_id', $change->authorizationId)
            ->lockForUpdate()
            ->first();
        if (
            $request === null
            || $token === null
            || $request->status !== DataChangeRequestStatus::AUTHORIZED
            || $token->consumed_at !== null
            || $token->revoked_at !== null
            || now('UTC')->gte($token->expires_at)
        ) {
            throw VoucherDomainException::tokenInvalid();
        }

        $fields = $change->fields;
        $expected = $token->field_scope;
        sort($fields);
        sort($expected);
        if (
            $request->client_id !== $change->clientId
            || $request->requested_by !== $change->cashierUserId
            || $request->branch_id !== $change->branchId
            || $token->client_id !== $change->clientId
            || $token->cashier_id !== $change->cashierUserId
            || $token->branch_id !== $change->branchId
            || $token->voucher_id !== $request->voucher_id
            || (string) $token->getRawOriginal('operation') !== (string) $request->getRawOriginal('operation')
            || $expected !== $fields
        ) {
            throw VoucherDomainException::tokenInvalid();
        }

        $token->forceFill(['consumed_at' => now('UTC')])->save();

        return new ConsumedChangeAuthorization(
            requestedBy: $request->requested_by,
            authorizedBy: (int) $request->decided_by,
        );
    }
}
