<?php

declare(strict_types=1);

namespace App\Modules\Relation\Application\Commands\GenerateRelationDocument;

readonly class GenerateRelationDocumentCommand
{
    public function __construct(
        public string $relationId
    ) {
    }
}
