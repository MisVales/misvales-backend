<?php

declare(strict_types=1);

namespace App\Modules\Relation\Domain\ValueObjects;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class DateRange
{
    public function __construct(
        private CarbonImmutable $startsAt,
        private CarbonImmutable $endsAt
    ) {
        if ($startsAt->greaterThan($endsAt)) {
            throw new InvalidArgumentException("Start date cannot be after end date");
        }
    }

    public function getStartsAt(): CarbonImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): CarbonImmutable
    {
        return $this->endsAt;
    }
}
