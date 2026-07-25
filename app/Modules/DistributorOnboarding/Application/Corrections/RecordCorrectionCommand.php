<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Corrections;

use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Application\Support\OperationMetadata;
use App\Modules\DistributorOnboarding\Domain\Expedients\ExpedientSection;

/** Corrección explícita sobre una ruta permitida y el valor previamente leído. */
final readonly class RecordCorrectionCommand
{
    public function __construct(
        public string $applicationPublicId,
        public int $lockVersion,
        public ExpedientSection $section,
        public string $fieldPath,
        public string $expectedOriginalValue,
        public string $correctedValue,
        public string $reason,
        public ?string $differencePublicId,
        public ActorContext $actor,
        public OperationMetadata $metadata,
    ) {}
}
