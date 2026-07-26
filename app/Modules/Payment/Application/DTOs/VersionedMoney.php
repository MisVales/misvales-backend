<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\DTOs;

use App\Modules\Payment\Domain\ValueObjects\Money;

/** Importe monetario acompañado de la versión de configuración que lo originó. */
final readonly class VersionedMoney
{
    public function __construct(
        public Money $amount,
        public string $versionId,
    ) {}
}
