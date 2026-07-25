<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Contracts;

/** Genera el folio visible sin acoplar el dominio a un formato comercial pendiente. */
interface FolioGenerator
{
    public function next(): string;
}
