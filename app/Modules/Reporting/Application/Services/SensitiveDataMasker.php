<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Services;

final class SensitiveDataMasker
{
    public function lastCharacters(?string $value, int $visible = 4): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $visible = max(0, $visible);
        $length = mb_strlen($value);
        if ($length <= $visible) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - $visible).mb_substr($value, -$visible);
    }
}
