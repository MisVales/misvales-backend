<?php

declare(strict_types=1);

namespace App\Modules\Client\Domain\Security;

/** Encapsula cifrado recuperable para permitir rotación sin afectar el dominio. */
interface SensitiveDataProtector
{
    public function encrypt(string $plainText): string;

    public function decrypt(string $cipherText): string;
}
