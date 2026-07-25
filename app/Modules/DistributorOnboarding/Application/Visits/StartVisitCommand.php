<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Visits;

use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Application\Support\OperationMetadata;

/** Contexto controlado para iniciar una visita asignada. */
final readonly class StartVisitCommand
{
    public function __construct(
        public string $applicationPublicId,
        public int $lockVersion,
        public ?string $authSessionPublicId,
        public ActorContext $actor,
        public OperationMetadata $metadata,
    ) {}
}
