<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\ValueObjects;

use Illuminate\Support\Str;

final readonly class VoucherFolio
{
    private function __construct(private string $value) {}

    public static function generate(): self
    {
        return new self((string) Str::ulid());
    }

    public function value(): string
    {
        return $this->value;
    }
}
