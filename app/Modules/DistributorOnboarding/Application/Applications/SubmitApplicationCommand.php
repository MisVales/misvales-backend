<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Applications;

use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Application\Support\OperationMetadata;

/** Datos para enviar una versión exacta del expediente. */
final readonly class SubmitApplicationCommand
{
    public function __construct(
        public string $applicationPublicId,
        public int $lockVersion,
        public ActorContext $actor,
        public OperationMetadata $metadata,
    ) {}
}
