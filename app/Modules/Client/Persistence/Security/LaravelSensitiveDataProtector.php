<?php

declare(strict_types=1);

namespace App\Modules\Client\Persistence\Security;

use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Client\Domain\Security\SensitiveDataProtector;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;

/** Adaptador de cifrado recuperable respaldado por el encrypter configurado de Laravel. */
final readonly class LaravelSensitiveDataProtector implements SensitiveDataProtector
{
    public function __construct(private Encrypter $encrypter) {}

    public function encrypt(string $plainText): string
    {
        return $this->encrypter->encryptString($plainText);
    }

    public function decrypt(string $cipherText): string
    {
        try {
            return $this->encrypter->decryptString($cipherText);
        } catch (DecryptException $exception) {
            throw ClientDomainException::sensitiveDataUnavailable($exception);
        }
    }
}
