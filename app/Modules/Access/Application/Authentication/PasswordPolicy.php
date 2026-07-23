<?php

namespace App\Modules\Access\Application\Authentication;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Infrastructure\Persistence\Models\PasswordHistory;
use Normalizer;
use SensitiveParameter;

final class PasswordPolicy
{
    public function validateAndNormalize(User $user, #[SensitiveParameter] string $password): string
    {
        $normalized = Normalizer::normalize($password, Normalizer::FORM_C);
        if ($normalized === false) {
            throw new AccessRuleViolation('La contraseña no cumple la política de seguridad.');
        }

        $length = mb_strlen($normalized);
        $valid = $length >= 12 && $length <= 128
            && preg_match('/\p{Ll}/u', $normalized) === 1
            && preg_match('/\p{Lu}/u', $normalized) === 1
            && preg_match('/\p{N}/u', $normalized) === 1
            && preg_match('/[^\p{L}\p{N}\s]/u', $normalized) === 1
            && preg_match('/\p{C}/u', $normalized) !== 1;
        if (! $valid) {
            throw new AccessRuleViolation('La contraseña no cumple la política de seguridad.');
        }

        $folded = mb_strtolower($normalized);
        if (str_contains($folded, mb_strtolower($user->normalized_email)) || str_contains($folded, mb_strtolower($user->name))) {
            throw new AccessRuleViolation('La contraseña no puede contener el correo o nombre visible.');
        }

        $compromised = file((string) config('access.security.compromised_passwords_file'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if (in_array($folded, array_map(mb_strtolower(...), $compromised), true)) {
            throw new AccessRuleViolation('La contraseña aparece en la lista local de credenciales comprometidas.');
        }

        $hashes = PasswordHistory::query()->where('user_id', $user->id)
            ->latest('recorded_at')->limit((int) config('access.security.password_history_count'))->pluck('password_hash');
        if ($user->password !== null) {
            $hashes->prepend($user->password);
        }
        foreach ($hashes->unique() as $hash) {
            if (password_verify($normalized, $hash)) {
                throw new AccessRuleViolation('No se puede reutilizar una de las últimas cinco contraseñas.');
            }
        }

        return $normalized;
    }
}
