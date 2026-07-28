<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\DTOs;

/** Cliente bloqueado y validado mediante el contrato público de M06. */
final readonly class ClientContext
{
    public function __construct(
        public string $id,
        public string $displayName,
        public bool $wasTransferred,
    ) {}
}
