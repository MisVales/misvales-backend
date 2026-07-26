<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Domain\Enums;

enum ClientTransferStatus: string
{
    case REQUESTED = 'REQUESTED';
    case PREACCEPTED = 'PREACCEPTED';
    case REJECTED_BY_RECIPIENT = 'REJECTED_BY_RECIPIENT';
    case ORIGIN_EXIT_AUTHORIZED = 'ORIGIN_EXIT_AUTHORIZED';
    case ORIGIN_EXIT_REJECTED = 'ORIGIN_EXIT_REJECTED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    public function isActive(): bool
    {
        return ! in_array($this, [
            self::REJECTED_BY_RECIPIENT,
            self::ORIGIN_EXIT_REJECTED,
            self::COMPLETED,
            self::CANCELLED,
        ], true);
    }
}
