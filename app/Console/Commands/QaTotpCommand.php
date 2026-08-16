<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MfaCredential;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

final class QaTotpCommand extends Command
{
    protected $signature = 'qa:totp {email}';

    protected $description = 'Genera el TOTP actual de un actor local/testing sin exponer su secreto';

    public function handle(Google2FA $google2fa): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('qa:totp solo está disponible en local/testing.');

            return self::FAILURE;
        }

        $email = Str::lower(trim((string) $this->argument('email')));
        $user = User::query()->where('normalized_email', $email)->first();

        if ($user === null) {
            $this->error('Actor QA inexistente.');

            return self::FAILURE;
        }

        $credential = MfaCredential::query()
            ->where('user_id', $user->id)
            ->where('type', 'TOTP')
            ->whereNull('revoked_at')
            ->first();

        if ($credential === null || $credential->secret_ciphertext === null) {
            $this->error('Actor QA sin TOTP activo.');

            return self::FAILURE;
        }

        $this->line($google2fa->getCurrentOtp(Crypt::decryptString($credential->secret_ciphertext)));

        return self::SUCCESS;
    }
}
