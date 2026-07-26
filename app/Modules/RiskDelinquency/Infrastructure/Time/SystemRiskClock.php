<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Time;

use App\Modules\RiskDelinquency\Application\Contracts\RiskClock;
use Carbon\CarbonImmutable;

final class SystemRiskClock implements RiskClock
{
    public function nowUtc(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public function nowOperational(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Monterrey');
    }
}
