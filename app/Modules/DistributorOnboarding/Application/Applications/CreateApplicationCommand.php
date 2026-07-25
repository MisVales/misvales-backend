<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Applications;

use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Application\Support\OperationMetadata;

/** Datos controlados para iniciar un expediente. */
final readonly class CreateApplicationCommand
{
    public function __construct(
        public string $contactEmail,
        public string $accountName,
        public ActorContext $actor,
        public OperationMetadata $metadata,
    ) {}
}
