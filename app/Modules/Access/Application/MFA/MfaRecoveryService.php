<?php

namespace App\Modules\Access\Application\MFA;

use App\Models\User;

final class MfaRecoveryService
{
    public function __construct(private readonly RecoveryCodeGenerator $generator) {}

    /**
     * Regenera y devuelve los 10 códigos de recuperación.
     * Revoca automáticamente los anteriores.
     *
     * @return array<int, string>
     */
    public function regenerate(User $user): array
    {
        return $this->generator->replaceFor($user);
    }
}
