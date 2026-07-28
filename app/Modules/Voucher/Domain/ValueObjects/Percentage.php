<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain\ValueObjects;

use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
use JsonSerializable;

final readonly class Percentage implements JsonSerializable
{
    private string $percentage;

    public function __construct(string $value)
    {
        $value = trim($value);
        if (! preg_match('/^\d+(?:\.\d{1,6})?$/', $value)
            || bccomp($value, '0', 6) < 0
            || bccomp($value, '1', 6) > 0) {
            throw VoucherDomainException::productIncomplete();
        }

        $this->percentage = bcadd($value, '0', 6);
    }

    public function value(): string
    {
        return $this->percentage;
    }

    public function jsonSerialize(): string
    {
        return $this->percentage;
    }
}
