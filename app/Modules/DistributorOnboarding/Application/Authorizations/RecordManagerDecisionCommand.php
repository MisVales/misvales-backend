<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Authorizations;

use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Application\Support\OperationMetadata;
use App\Modules\DistributorOnboarding\Domain\Decisions\ManagerDecision;

/** Decisión final; el importe y la reautenticación solo aplican a APPROVE. */
final readonly class RecordManagerDecisionCommand
{
    public function __construct(
        public string $applicationPublicId,
        public int $lockVersion,
        public ManagerDecision $decision,
        public ?string $initialCreditLine,
        public string $reason,
        public ?string $reauthenticationToken,
        public ActorContext $actor,
        public OperationMetadata $metadata,
    ) {}
}
