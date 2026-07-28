<?php

declare(strict_types=1);

namespace App\Modules\Relation\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RelationGenerationFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $cutRunId,
        public readonly string $distributorId,
        public readonly string $errorCode
    ) {}
}
