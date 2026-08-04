<?php

namespace App\Services\Auth;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;

class RoleAssignmentPolicyService
{
    /**
     * Diccionario de rangos para establecer la jerarquía.
     * Un rol solo puede asignar roles con un rango ESTRICTAMENTE MENOR al suyo máximo.
     */
    private $ranks = [
        'general_manager' => 100,
        'admin'           => 90,
        'branch_manager'  => 80,
        'coordinator'     => 60,
        'verifier'        => 40,
        'cashier'         => 20,
    ];

    /**
     * Valida de manera estricta las reglas de negocio antes de una asignación.
     * Retorna true si es válido, o un mensaje de error si falla.
     */
    public function validateAssignment(User $actor, User $targetUser, Role $roleToAssign, ?string $branchId)
    {
        // 1. Estado del usuario receptor
        if ($targetUser->state !== 'ACTIVE') {
            return 'El usuario receptor debe estar en estado ACTIVO.';
        }

        // 2. Jerarquía
        $actorMaxRank = $this->getActorMaxRank($actor);
        $roleRank = $this->ranks[$roleToAssign->code] ?? 10; // Roles no listados tienen rango bajo
        
        if ($roleRank >= $actorMaxRank) {
            return "No tienes el nivel jerárquico suficiente para asignar el rol de '{$roleToAssign->name}'.";
        }

        // 3. Alcance del Actor
        if (!$this->actorHasScopeOverBranch($actor, $branchId)) {
            return 'No tienes jurisdicción sobre la sucursal seleccionada para realizar asignaciones.';
        }

        // 4. Separación de Funciones (Segregation of Duties)
        $sodConflict = $this->checkSegregationOfDuties($targetUser, $roleToAssign, $branchId);
        if ($sodConflict) {
            return "Violación de Separación de Funciones (SoD): {$sodConflict}";
        }

        return true;
    }

    /**
     * Obtiene el rango jerárquico más alto que posee el actor.
     */
    private function getActorMaxRank(User $actor): int
    {
        $maxRank = 0;
        $scopes = UserRoleScope::with('role')
            ->where('user_id', $actor->id)
            ->whereNull('revoked_at')
            ->get();

        foreach ($scopes as $scope) {
            $rank = $this->ranks[$scope->role->code] ?? 0;
            if ($rank > $maxRank) {
                $maxRank = $rank;
            }
        }
        return $maxRank;
    }

    /**
     * Verifica si el actor tiene poder sobre la sucursal.
     * Un actor global (branch_id = null) tiene poder sobre todo.
     * Un actor local solo tiene poder sobre su sucursal.
     */
    private function actorHasScopeOverBranch(User $actor, ?string $targetBranchId): bool
    {
        $scopes = UserRoleScope::where('user_id', $actor->id)
            ->whereNull('revoked_at')
            ->get();

        foreach ($scopes as $scope) {
            // Si el actor tiene un rol global, puede asignar donde sea.
            if ($scope->branch_id === null) return true;
            // Si coincide la sucursal, puede asignar ahí.
            if ($scope->branch_id === $targetBranchId) return true;
        }

        return false;
    }

    /**
     * Aplica la matriz de riesgo de Separación de Funciones (SoD).
     */
    private function checkSegregationOfDuties(User $targetUser, Role $newRole, ?string $branchId): ?string
    {
        $activeRolesInBranch = UserRoleScope::with('role')
            ->where('user_id', $targetUser->id)
            ->where('branch_id', $branchId)
            ->whereNull('revoked_at')
            ->get()
            ->pluck('role.code')
            ->toArray();

        // Regla SoD 1: Un Cajero no puede ser Verificador en la misma sucursal
        if ($newRole->code === 'cashier' && in_array('verifier', $activeRolesInBranch)) {
            return 'Un verificador activo no puede adquirir el rol de cajero.';
        }
        if ($newRole->code === 'verifier' && in_array('cashier', $activeRolesInBranch)) {
            return 'Un cajero activo no puede adquirir el rol de verificador.';
        }

        return null;
    }
}
