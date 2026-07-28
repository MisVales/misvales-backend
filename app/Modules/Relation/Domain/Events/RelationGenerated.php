<?php

declare(strict_types=1);

namespace App\Modules\Relation\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RelationGenerated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $relationId,
        public readonly string $distributorId,
        public readonly string $cutRunId,
        public readonly string $dueAt,
        public readonly string $paymentReference,
        public readonly string $portfolioTotal,
        public readonly string $misvalesDueTotal
    ) {}
}
