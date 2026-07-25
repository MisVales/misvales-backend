<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Domain\Expedients;

use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;

/** Correo normalizado que será entregado a M01 solo después de autorizar. */
final readonly class NormalizedEmail
{
    public string $value;

    public function __construct(string $email)
    {
        $normalized = mb_strtolower(trim($email));

        if (mb_strlen($normalized) > 254 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw OnboardingDomainException::invalidEmail();
        }

        $this->value = $normalized;
    }

    public function protectedHash(string $key): string
    {
        return hash_hmac('sha256', $this->value, $key);
    }
}
