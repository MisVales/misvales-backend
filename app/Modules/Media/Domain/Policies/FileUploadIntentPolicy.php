<?php

namespace App\Modules\Media\Domain\Policies;

use App\Models\User;
use App\Modules\Media\Persistence\Models\FileUploadIntent;
use Illuminate\Auth\Access\HandlesAuthorization;

class FileUploadIntentPolicy
{
    use HandlesAuthorization;

    public function upload(User $user, FileUploadIntent $intent): bool
    {
        // Solo el usuario actor al que se le creó la intención puede consumirla
        return $intent->actor_user_id === $user->id;
    }
}
