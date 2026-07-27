<?php

namespace App\Modules\Distributor\Domain\Policies;

use App\Models\User; // Usando el modelo de usuario por defecto
use App\Modules\Distributor\Persistence\Models\Distributor;
use Illuminate\Auth\Access\HandlesAuthorization;

class DistributorPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        // Validación de permisos según matriz (DI10)
        return true; 
    }

    public function view(User $user, Distributor $distributor): bool
    {
        return true; 
    }

    public function viewHistory(User $user, Distributor $distributor): bool
    {
        return true; 
    }

    public function viewSelf(User $user, Distributor $distributor): bool
    {
        return $distributor->user_id === $user->id;
    }

    public function assignCategory(User $user, Distributor $distributor): bool
    {
        return true; 
    }
}
