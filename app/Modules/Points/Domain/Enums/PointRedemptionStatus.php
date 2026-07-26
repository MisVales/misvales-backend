<?php

declare(strict_types=1);

namespace App\Modules\Points\Domain\Enums;

enum PointRedemptionStatus: string
{
    case PENDING = 'PENDING';
    case AUTHORIZED = 'AUTHORIZED';
    case REJECTED = 'REJECTED';
    case COMPLETED = 'COMPLETED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::REJECTED, self::COMPLETED], true);
    }
}
