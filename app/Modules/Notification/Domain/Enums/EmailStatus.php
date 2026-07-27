<?php

namespace App\Modules\Notification\Domain\Enums;

enum EmailStatus: string
{
    case PENDING = 'PENDING';
    case QUEUED = 'QUEUED';
    case SENDING = 'SENDING';
    case SENT = 'SENT';
    case RETRYABLE_FAILED = 'RETRYABLE_FAILED';
    case PERMANENT_FAILED = 'PERMANENT_FAILED';
}
