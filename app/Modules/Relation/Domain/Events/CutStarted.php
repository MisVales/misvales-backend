<?php

declare(strict_types=1);

namespace App\Modules\Relation\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CutStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $cutRunId,
        public readonly string $cutDate,
        public readonly array $configurationSnapshot,
        public readonly string $triggerType
    ) {}
}
