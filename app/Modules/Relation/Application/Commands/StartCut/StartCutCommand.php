<?php

declare(strict_types=1);

namespace App\Modules\Relation\Application\Commands\StartCut;

use Carbon\CarbonImmutable;

readonly class StartCutCommand
{
    public function __construct(
        public CarbonImmutable $operativeDate,
        public string $triggerType,
        public ?string $triggeredBy = null
    ) {}
}
