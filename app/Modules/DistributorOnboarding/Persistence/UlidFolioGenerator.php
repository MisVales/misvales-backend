<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence;

use App\Modules\DistributorOnboarding\Domain\Contracts\FolioGenerator;
use Illuminate\Support\Str;

/** Generador técnico opaco reemplazable cuando se apruebe el formato visible. */
final class UlidFolioGenerator implements FolioGenerator
{
    public function next(): string
    {
        return (string) Str::ulid();
    }
}
