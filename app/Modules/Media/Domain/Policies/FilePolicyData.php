<?php

namespace App\Modules\Media\Domain\Policies;

/**
 * DTO que define las restricciones técnicas autorizadas para la carga y validación
 * de un tipo de evidencia particular.
 *
 * Utilizado por M18 para contrastar la extensión, MIME y tamaño reales del archivo.
 */
class FilePolicyData
{
    public function __construct(
        public readonly string $purpose,
        public readonly array $allowedExtensions,
        public readonly array $allowedMimes,
        public readonly int $maxSizeBytes,
        public readonly bool $requiresValidation = true,
        public readonly bool $allowPreview = false,
        public readonly bool $allowDownload = true
    ) {}
}
