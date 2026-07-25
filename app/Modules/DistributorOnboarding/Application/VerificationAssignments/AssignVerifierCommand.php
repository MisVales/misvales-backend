<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\VerificationAssignments;

use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Application\Support\OperationMetadata;

/** Verificador seleccionado para una solicitud revisada. */
final readonly class AssignVerifierCommand
{
    public function __construct(
        public string $applicationPublicId,
        public string $verifierPublicId,
        public int $lockVersion,
        public ActorContext $actor,
        public OperationMetadata $metadata,
    ) {}
}
