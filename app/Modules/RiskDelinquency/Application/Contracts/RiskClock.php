<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Contracts;

use Carbon\CarbonImmutable;

interface RiskClock
{
    public function nowUtc(): CarbonImmutable;

    public function nowOperational(): CarbonImmutable;
}
