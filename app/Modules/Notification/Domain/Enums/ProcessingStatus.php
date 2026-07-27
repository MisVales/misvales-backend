<?php

namespace App\Modules\Notification\Domain\Enums;

enum ProcessingStatus: string
{
    case RECEIVED = 'RECEIVED';
    case PROCESSING = 'PROCESSING';
    case PROCESSED = 'PROCESSED';
    case PARTIALLY_FAILED = 'PARTIALLY_FAILED';
    case FAILED = 'FAILED';
    case UNSUPPORTED = 'UNSUPPORTED';
}
