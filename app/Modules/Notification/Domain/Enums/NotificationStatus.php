<?php

namespace App\Modules\Notification\Domain\Enums;

enum NotificationStatus: string
{
    case UNREAD = 'UNREAD';
    case READ = 'READ';
}
