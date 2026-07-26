<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Enums;

enum ReportRunStatus: string
{
    case QUEUED = 'QUEUED';
    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case EXPIRED = 'EXPIRED';

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::QUEUED => in_array($next, [self::RUNNING, self::FAILED], true),
            self::RUNNING => in_array($next, [self::COMPLETED, self::FAILED], true),
            self::COMPLETED, self::FAILED => $next === self::EXPIRED,
            self::EXPIRED => false,
        };
    }
}
