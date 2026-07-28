<?php

declare(strict_types=1);

namespace App\Modules\Relation\Domain\Events;

use App\Modules\Relation\Domain\Enums\CutRunStatus;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CutCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $cutRunId,
        public readonly CutRunStatus $finalStatus,
        public readonly int $distributorsEvaluated,
        public readonly int $relationsGenerated,
        public readonly int $distributorsWithoutItems,
        public readonly int $failedAttempts
    ) {}
}
