<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Integrations;

use App\Models\User;
use App\Modules\Client\Application\Contracts\ResolveClientForVoucher;
use App\Modules\Client\Application\Security\ClientActorContextFactory;
use App\Modules\Client\Domain\Assignments\AssignmentType;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Client\Persistence\Models\Client;
use App\Modules\Client\Persistence\Models\ClientDistributorAssignment;
use App\Modules\Voucher\Application\Contracts\ClientVoucherGateway;
use App\Modules\Voucher\Application\DTOs\ClientContext;
use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;

/** Valida mediante M06 y luego mantiene bloqueada la identidad del cliente. */
final readonly class EloquentClientVoucherGateway implements ClientVoucherGateway
{
    public function __construct(
        private ResolveClientForVoucher $resolver,
        private ClientActorContextFactory $contexts,
    ) {}

    public function lockAssigned(string $clientId, User $actor): ClientContext
    {
        try {
            $selection = $this->resolver->handle($clientId, $this->contexts->fromUser($actor));
        } catch (ClientDomainException $exception) {
            if ($exception->errorCode() === 'CLIENT_DEPENDENCY_UNAVAILABLE') {
                throw VoucherDomainException::distributorInactive();
            }

            throw VoucherDomainException::clientNotAssigned();
        }

        $client = Client::query()->whereKey($selection->clientId)->lockForUpdate()->first();
        $assignment = ClientDistributorAssignment::query()
            ->where('client_id', $selection->clientId)
            ->where('active_slot', true)
            ->lockForUpdate()
            ->first();
        if (
            $client === null
            || $assignment === null
            || $assignment->distributor_id !== $selection->distributorId
        ) {
            throw VoucherDomainException::clientNotAssigned();
        }

        return new ClientContext(
            id: $client->id,
            displayName: trim($client->given_names.' '.$client->surnames),
            wasTransferred: $assignment->assignment_type === AssignmentType::AUTHORIZED_TRANSFER,
        );
    }
}
