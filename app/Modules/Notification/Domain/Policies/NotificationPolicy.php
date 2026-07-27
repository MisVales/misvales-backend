<?php

namespace App\Modules\Notification\Domain\Policies;

use App\Models\User;
use App\Modules\Notification\Persistence\Models\Notification;
use Illuminate\Auth\Access\HandlesAuthorization;

class NotificationPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true; 
    }

    public function read(User $user, Notification $notification): bool
    {
        return $notification->user_id === $user->id;
    }
}
