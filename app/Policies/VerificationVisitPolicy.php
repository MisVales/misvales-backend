<?php
namespace App\Policies;
use App\Models\User;
use App\Models\VerificationVisit;

class VerificationVisitPolicy {
    public function before(User $user) {
        if ($user->hasRole('general_manager') || $user->hasRole('admin')) return true;
    }
    public function view(User $user, VerificationVisit $visit) {
        if ($user->hasRole('verifier')) return $user->id === $visit->verifier_id;
        return false; // App Policy handles managers
    }
    public function update(User $user, VerificationVisit $visit) {
        return $user->id === $visit->verifier_id;
    }
}
