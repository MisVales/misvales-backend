<?php

declare(strict_types=1);

namespace App\Modules\Relation\Application\Commands\GenerateRelation;

readonly class GenerateRelationCommand
{
    public function __construct(
        public string $cutRunId,
        public string $distributorId
    ) {
    }
}
