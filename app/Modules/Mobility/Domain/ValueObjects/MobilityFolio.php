<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Domain\ValueObjects;

use InvalidArgumentException;

/** Folio visible opaco y estable. */
final readonly class MobilityFolio
{
    public function __construct(public string $value)
    {
        if (! preg_match('/^MV15-(TR|RA|SC|CO)-[A-Z0-9]{12}$/', $value)) {
            throw new InvalidArgumentException('Invalid mobility folio.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
