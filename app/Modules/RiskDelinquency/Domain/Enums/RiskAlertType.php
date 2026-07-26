<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Domain\Enums;

enum RiskAlertType: string
{
    case FIRST_BREACH = 'FIRST_BREACH';
    case SECOND_BREACH = 'SECOND_BREACH';
    case THIRD_BREACH = 'THIRD_BREACH';

    public function threshold(): int
    {
        return match ($this) {
            self::FIRST_BREACH => 1,
            self::SECOND_BREACH => 2,
            self::THIRD_BREACH => 3,
        };
    }
}
