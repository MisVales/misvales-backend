<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Evaluations;

use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Application\Support\OperationMetadata;
use App\Modules\DistributorOnboarding\Domain\Decisions\CoordinatorDecision;

/** Evaluación del coordinador sobre una versión exacta del expediente. */
final readonly class RecordCoordinatorDecisionCommand
{
    public function __construct(
        public string $applicationPublicId,
        public int $lockVersion,
        public CoordinatorDecision $decision,
        public string $reason,
        public ActorContext $actor,
        public OperationMetadata $metadata,
    ) {}
}
