<?php

namespace App\Modules\Media\Domain\Policies;

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
