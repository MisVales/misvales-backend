<?php

namespace App\Modules\Access\Application\Authentication;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Infrastructure\Persistence\Models\PasswordHistory;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * Validates user-managed passwords before they are stored.
 */
final class PasswordPolicy
{
    /**
     * @throws AccessRuleViolation When the password is too weak or was recently used.
     */
    public function validateAndNormalize(User $user, #[SensitiveParameter] string $password): string
    {
        $normalized = trim($password);
        if (mb_strlen($normalized) < 12) {
            throw new AccessRuleViolation('La contraseña debe tener al menos 12 caracteres.');
        }

        if (! preg_match('/[a-z]/', $normalized) || ! preg_match('/[A-Z]/', $normalized) || ! preg_match('/\d/', $normalized)) {
            throw new AccessRuleViolation('La contraseña debe combinar mayúsculas, minúsculas y números.');
        }

        $recentHashes = PasswordHistory::query()
            ->where('user_id', $user->id)
            ->latest('recorded_at')
            ->limit((int) config('access.security.password_history_count'))
            ->pluck('password_hash');

        foreach ($recentHashes as $hash) {
            if (is_string($hash) && Hash::check($normalized, $hash)) {
                throw new AccessRuleViolation('La contraseña ya fue usada recientemente.');
            }
        }

        return $normalized;
    }
}
