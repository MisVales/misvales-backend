<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Contracts;

/** Referencia privada validada por el módulo propietario de archivos. */
final readonly class DocumentReference
{
    public function __construct(
        public string $mediaId,
        public ?string $fingerprint,
    ) {}
}
