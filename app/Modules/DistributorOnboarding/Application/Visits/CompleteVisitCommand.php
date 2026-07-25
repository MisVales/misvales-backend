<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Visits;

use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Application\Support\OperationMetadata;
use App\Modules\DistributorOnboarding\Domain\Verification\VisitResult;

/** Resultado final y observaciones de una visita. */
final readonly class CompleteVisitCommand
{
    public function __construct(
        public string $applicationPublicId,
        public string $visitPublicId,
        public int $lockVersion,
        public VisitResult $result,
        public string $observations,
        public ActorContext $actor,
        public OperationMetadata $metadata,
    ) {}
}
