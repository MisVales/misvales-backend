<?php

declare(strict_types=1);

namespace App\Modules\Client\Domain\Security;

/** Genera huellas irreversibles para coincidencia exacta y unicidad. */
interface ExactMatchHmac
{
    public function make(string $normalizedValue): string;
}
