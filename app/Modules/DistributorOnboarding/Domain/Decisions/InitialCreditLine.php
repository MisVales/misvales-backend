<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Decisions;

use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;

/** Importe decimal exacto de la línea inicial, sin límites funcionales inventados. */
final readonly class InitialCreditLine
{
    public string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if (preg_match('/^(0|[1-9]\d{0,14})(\.\d{1,4})?$/', $trimmed) !== 1) {
            throw OnboardingDomainException::invalidInitialCreditLine();
        }

        $this->value = bcadd($trimmed, '0', 4);
    }
}
