<?php

declare(strict_types=1);

namespace App\Modules\Credit\Application\Services;

use App\Modules\Access\Domain\Accounts\DistributorAccessProvisioned;
use App\Modules\Access\Infrastructure\Persistence\Models\DistributorAccessLink;
use App\Modules\Credit\Application\DTOs\InitialCreditAuthorization;
use App\Modules\Credit\Domain\ValueObjects\Money;

final readonly class ProvisionInitialCreditLine
{
    public function __construct(private InitialCreditLineService $service) {}

    public function handle(DistributorAccessProvisioned $event): void
    {
        $link = DistributorAccessLink::query()
            ->where('external_distributor_id', $event->distributorId)
            ->firstOrFail();

        $this->service->register(new InitialCreditAuthorization(
            distributorId: $link->user_id,
            authorizedAmount: new Money($link->initial_credit_line),
            authorizedByUserId: $link->authorized_by,
            branchId: $link->branch_id,
            reason: 'Línea inicial autorizada en el alta de la distribuidora.',
            authorizationId: $link->external_request_id,
            idempotencyKey: "credit-initial:{$event->eventKey}",
        ));
    }
}
