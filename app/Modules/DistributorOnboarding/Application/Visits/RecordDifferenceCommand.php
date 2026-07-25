<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Visits;

use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Application\Support\OperationMetadata;
use App\Modules\DistributorOnboarding\Domain\Expedients\ExpedientSection;

/** Diferencia declarada por el verificador sin modificar el expediente. */
final readonly class RecordDifferenceCommand
{
    public function __construct(
        public string $applicationPublicId,
        public string $visitPublicId,
        public int $lockVersion,
        public ExpedientSection $section,
        public string $fieldPath,
        public string $declaredValue,
        public string $observedValue,
        public string $description,
        public string $classificationCode,
        public ?string $evidenceMediaId,
        public ActorContext $actor,
        public OperationMetadata $metadata,
    ) {}
}
