<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Reviews;

use App\Modules\DistributorOnboarding\Application\Security\ActorContext;
use App\Modules\DistributorOnboarding\Application\Support\OperationMetadata;

/** Motivo y versión para devolver el expediente a captura. */
final readonly class RequestDocumentCorrectionCommand
{
    public function __construct(
        public string $applicationPublicId,
        public int $lockVersion,
        public string $reason,
        public ActorContext $actor,
        public OperationMetadata $metadata,
    ) {}
}
