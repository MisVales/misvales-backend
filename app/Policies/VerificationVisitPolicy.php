<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VerificationVisit;

class VerificationVisitPolicy
{
    public function before(User $user)
    {
        // Administradores y gerentes generales tienen acceso global de solo lectura (view), pero no blanket de escritura
    }

    public function view(User $user, VerificationVisit $visit)
    {
        if ($user->hasRole('admin') || $user->hasRole('general_manager')) {
            return true;
        }
        if ($user->hasRole('verifier')) {
            return $user->id === $visit->verifier_id;
        }
        if ($user->hasRole('branch_manager') || $user->hasRole('coordinator')) {
            return $user->branch_id === $visit->application->branch_id;
        }

        return false;
    }

    public function update(User $user, VerificationVisit $visit)
    {
        return $user->hasRole('verifier') && $user->id === $visit->verifier_id;
    }
}
