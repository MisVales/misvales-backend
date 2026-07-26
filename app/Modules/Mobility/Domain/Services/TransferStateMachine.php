<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Domain\Services;

use App\Modules\Mobility\Domain\Enums\ClientTransferStatus;
use App\Modules\Mobility\Domain\Exceptions\MobilityException;

/** Matriz cerrada de transiciones de una transferencia. */
final class TransferStateMachine
{
    public function assert(ClientTransferStatus $current, ClientTransferStatus $next): void
    {
        $allowed = match ($current) {
            ClientTransferStatus::REQUESTED => [
                ClientTransferStatus::PREACCEPTED,
                ClientTransferStatus::REJECTED_BY_RECIPIENT,
            ],
            ClientTransferStatus::PREACCEPTED => [
                ClientTransferStatus::ORIGIN_EXIT_AUTHORIZED,
                ClientTransferStatus::ORIGIN_EXIT_REJECTED,
            ],
            ClientTransferStatus::ORIGIN_EXIT_AUTHORIZED => [
                ClientTransferStatus::COMPLETED,
                ClientTransferStatus::CANCELLED,
            ],
            default => [],
        };

        if (! in_array($next, $allowed, true)) {
            throw MobilityException::invalidState();
        }
    }
}
