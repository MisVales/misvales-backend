<?php

declare(strict_types=1);

namespace App\Modules\Points\Domain\ValueObjects;

use App\Modules\Points\Domain\Exceptions\PointsDomainException;

final readonly class PointBalance
{
    public int $available;

    public function __construct(
        public int $total,
        public int $reserved,
    ) {
        $this->available = $total - $reserved;

        if ($total < 0 || $reserved < 0 || $this->available < 0) {
            throw new PointsDomainException(
                'POINT_ACCOUNT_INCONSISTENT',
                'La cuenta de puntos no cumple sus invariantes.',
                423,
            );
        }
    }
}
