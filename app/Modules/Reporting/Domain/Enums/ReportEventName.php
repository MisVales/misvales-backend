<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Enums;

enum ReportEventName: string
{
    case RUN_REQUESTED = 'ReportRunRequested';
    case RUN_STARTED = 'ReportRunStarted';
    case RUN_COMPLETED = 'ReportRunCompleted';
    case RUN_FAILED = 'ReportRunFailed';
    case RUN_EXPIRED = 'ReportRunExpired';
    case SENSITIVE_ACCESSED = 'SensitiveReportAccessed';
    case ACCESS_DENIED = 'ReportAccessDenied';
}
