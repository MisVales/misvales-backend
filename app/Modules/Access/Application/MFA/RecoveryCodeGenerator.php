<?php

namespace App\Modules\Access\Application\MFA;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaRecoveryCode;

final class RecoveryCodeGenerator
{
    /** @return list<string> */
    public function replaceFor(User $user): array
    {
        MfaRecoveryCode::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $plainCodes = [];
        for ($index = 0; $index < (int) config('access.security.recovery_code_count'); $index++) {
            $plain = strtoupper(bin2hex(random_bytes(5)));
            $plain = substr($plain, 0, 5).'-'.substr($plain, 5);
            MfaRecoveryCode::query()->create([
                'user_id' => $user->id,
                'code_hash' => hash('sha256', $plain),
                'issued_at' => now(),
            ]);
            $plainCodes[] = $plain;
        }

        return $plainCodes;
    }
}
