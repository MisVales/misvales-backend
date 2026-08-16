<?php

namespace App\Services\Auth;

use App\Models\Role;
use App\Models\User;
use App\Models\UserRoleScope;
use Illuminate\Database\Eloquent\Collection;
use App\Modules\Organization\Domain\Assignments\Exceptions\RoleScopeNotAllowed;
use App\Modules\Organization\Domain\Assignments\Services\OrganizationAssignmentRules;
use App\Modules\Organization\Domain\Assignments\ValueObjects\OrganizationScope;

class RoleAssignmentPolicyService
{
    public function __construct(
        private readonly OrganizationAssignmentRules $assignmentRules,
    ) {}

    /**
     * Diccionario de rangos para establecer la jerarquía.
     * Un rol solo puede asignar roles con un rango ESTRICTAMENTE MENOR al suyo máximo.
     */
    private $ranks = [
        'general_manager' => 100,
        'admin' => 90,
        'branch_manager' => 80,
        'coordinator' => 60,
        'verifier' => 40,
        'cashier' => 20,
    ];

    /**
     * Valida de manera estricta las reglas de negocio antes de una asignación.
     * Retorna true si es válido, o un mensaje de error si falla.
     */
    public function validateAssignment(User $actor, User $targetUser, Role $roleToAssign, ?string $branchId)
    {
        // 1. Estado del usuario receptor
        if (! in_array($targetUser->state, ['ACTIVE', 'INVITED', 'PENDING_ACTIVATION'])) {
            return 'El usuario receptor no está en un estado válido para recibir asignaciones.';
        }

        // 2. Alcance permitido por la matriz organizacional autoritativa.
        // Los controladores heredados solo representan GLOBAL o BRANCH.
        $scope = $branchId === null ? OrganizationScope::GLOBAL : OrganizationScope::BRANCH;

        try {
            $this->assignmentRules->assertRoleAllowsScope($roleToAssign->code, $scope);
        } catch (RoleScopeNotAllowed $exception) {
            return $exception->getMessage();
        }

        // 3. Jerarquía
        $actorMaxRank = $this->getActorMaxRank($actor);
        $roleRank = $this->ranks[$roleToAssign->code] ?? 10; // Roles no listados tienen rango bajo

        if ($roleRank >= $actorMaxRank) {
            return "No tienes el nivel jerárquico suficiente para asignar el rol de '{$roleToAssign->name}'.";
        }

        // 4. Alcance del Actor
        if (! $this->actorHasScopeOverBranch($actor, $branchId)) {
            return 'No tienes jurisdicción sobre la sucursal seleccionada para realizar asignaciones.';
        }

        // 5. Separación de Funciones (Segregation of Duties)
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
            ->where('status', 'ACTIVE')
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
        $scopes = UserRoleScope::with('role')
            ->where('user_id', $actor->id)
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->get();

        foreach ($scopes as $scope) {
            // Solo los roles globales definidos por la matriz tienen
            // jurisdicción sobre todas las sucursales.
            if ($scope->scope_type === 'GLOBAL'
                && in_array($scope->role?->code, ['general_manager', 'admin'], true)) {
                return true;
            }

            if ($scope->scope_type === 'BRANCH' && $scope->branch_id === $targetBranchId) {
                return true;
            }
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
            ->where('status', 'ACTIVE')
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

    /**
     * Retorna los roles que el actor puede asignar según su jerarquía.
     */
    public function getAssignableRoles(User $actor): Collection
    {
        $actorMaxRank = $this->getActorMaxRank($actor);

        return Role::where('is_active', true)
            ->whereIn('code', array_keys($this->ranks))
            ->get()
            ->filter(fn (Role $role) => ($this->ranks[$role->code] ?? 0) < $actorMaxRank)
            ->values();
    }
}
