<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Corrections;

use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Application\Support\OperationMetadata;

/** Confirmación de una versión sin diferencias corregibles pendientes. */
final readonly class CompleteCorrectionsCommand
{
    public function __construct(
        public string $applicationPublicId,
        public int $lockVersion,
        public ActorContext $actor,
        public OperationMetadata $metadata,
    ) {}
}
