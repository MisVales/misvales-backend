<?php

namespace App\Modules\Access\Application\MFA;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaRecoveryCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Manages generation and tracking of MFA recovery codes.
 *
 * Per spec B05.3:
 * - Generates exactly 10 recovery codes
 * - Codes are impredictable, sufficiently long, and human-readable
 * - Shown only once during generation; hash stored separately
 * - One use per code
 * - Never sent by email
 * - Regeneration invalidates all previous codes and requires reauthentication
 */
final readonly class RecoveryCodeGenerator
{
    public const CODE_COUNT = 10;

    public const CODE_FORMAT = 'XXXX-XXXX-XXXX-XXXX';

    /**
     * Generate and store new recovery codes for a user.
     * Replaces all existing codes.
     *
     * @return array<int, string> Array of 10 plain recovery codes to display to user
     */
    public function replaceFor(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            // Revoke all existing codes
            MfaRecoveryCode::query()
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);

            $plainCodes = [];
            $now = CarbonImmutable::now();

            for ($i = 0; $i < self::CODE_COUNT; $i++) {
                $plainCode = $this->generatePlainCode();
                $plainCodes[] = $plainCode;

                MfaRecoveryCode::query()->create([
                    'user_id' => $user->id,
                    'code_hash' => self::hashCode($plainCode),
                    'generated_at' => $now,
                ]);
            }

            return $plainCodes;
        });
    }

    /**
     * Generate a single recovery code in human-readable format.
     * Format: XXXX-XXXX (8 hex characters with hyphen)
     */
    private function generatePlainCode(): string
    {
        $bytes = random_bytes(8);
        $hex = bin2hex($bytes);

        return strtoupper(implode('-', str_split($hex, 4)));
    }

    public static function hashCode(string $plainCode): string
    {
        return hash_hmac('sha256', strtoupper(trim($plainCode)), (string) config('app.key'));
    }
}
