<?php

namespace App\Modules\Access\Application\MFA;

use App\Models\User;
use App\Modules\Access\Domain\MFA\MfaType;
use App\Modules\Access\Infrastructure\Persistence\Models\MfaCredential;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class TotpService
{
    public function __construct(private readonly TotpVerifier $verifier) {}

    /**
     * Inicia el proceso de registro de TOTP.
     * Genera un secreto temporal que el usuario debe confirmar.
     *
     * @return array{secret: string, uri: string}
     */
    public function initiate(User $user): array
    {
        return $this->verifier->generateSecret($user->email);
    }

    /**
     * Confirma el registro de TOTP verificando el primer código.
     *
     * @throws RuntimeException Si el código es inválido.
     */
    public function confirm(User $user, string $base32Secret, string $code): void
    {
        if (! $this->verifier->verify($base32Secret, $code)) {
            throw new RuntimeException('El código TOTP no es válido.');
        }

        DB::transaction(function () use ($user, $base32Secret) {
            // Solo se permite un TOTP activo a la vez
            MfaCredential::query()
                ->where('user_id', $user->id)
                ->where('type', MfaType::TOTP->value)
                ->delete();

            MfaCredential::query()->create([
                'user_id' => $user->id,
                'type' => MfaType::TOTP->value,
                'credential_identifier' => 'totp-'.$user->id,
                'encrypted_secret' => Crypt::encryptString($base32Secret),
                'metadata' => [],
                'state' => 'ACTIVE',
            ]);
        });
    }

    /**
     * Retira el factor TOTP del usuario, si no es su último factor activo.
     *
     * @throws RuntimeException Si al retirar dejaría la cuenta sin factores.
     */
    public function destroy(User $user): void
    {
        DB::transaction(function () use ($user) {
            $activeFactors = MfaCredential::query()
                ->where('user_id', $user->id)
                ->where('state', 'ACTIVE')
                ->get();

            $totp = $activeFactors->first(
                fn (MfaCredential $credential): bool => $credential->type === MfaType::TOTP
            );

            if (! $totp) {
                return; // No hay TOTP, no hacemos nada
            }

            if ($activeFactors->count() === 1) {
                throw new RuntimeException('No se puede retirar el único factor de autenticación activo.');
            }

            $totp->delete();
        });
    }
}
