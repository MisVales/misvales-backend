<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Applications;

use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Application\Support\OperationMetadata;

/** Cambio parcial tipado por sección. */
final readonly class UpdateCaptureCommand
{
    /** @param array<string, mixed> $personalData Solo admite los campos personales definidos por M04. */
    public function __construct(
        public string $applicationPublicId,
        public int $lockVersion,
        public ?string $contactEmail,
        public ?string $accountName,
        public array $personalData,
        public ActorContext $actor,
        public OperationMetadata $metadata,
    ) {}
}
