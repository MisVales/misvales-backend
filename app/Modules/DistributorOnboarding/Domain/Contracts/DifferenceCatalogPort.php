<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Contracts;

/** Valida clasificaciones de diferencias cuando el catálogo funcional sea aprobado. */
interface DifferenceCatalogPort
{
    public function assertApproved(string $classificationCode): void;
}
