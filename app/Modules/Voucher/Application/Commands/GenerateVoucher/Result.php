<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Commands\GenerateVoucher;

final readonly class Result
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public array $data,
        public bool $replayed,
    ) {}
}
