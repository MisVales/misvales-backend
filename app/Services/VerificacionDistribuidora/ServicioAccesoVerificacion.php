<?php

namespace App\Services\VerificacionDistribuidora;

use App\Exceptions\BusinessException;
use App\Helpers\AuditHelper;
use App\Models\DistributorApplication;
use App\Models\User;
use App\Models\VerificationVisit;

class ServicioAccesoVerificacion
{
    public function usuarioActivo(string $userId): User
    {
        $user = User::query()->find($userId);

        if ($user === null || $user->state !== 'ACTIVE') {
            throw new BusinessException('AUTH_USER_INACTIVE', 'El usuario no está activo.', 403);
        }

        return $user;
    }

    public function exigirCoordinador(DistributorApplication $application, string $userId): User
    {
        $user = $this->usuarioActivo($userId);

        if ($application->coordinator_id !== $userId
            || ! $this->tieneRolEnSucursal($user, 'coordinator', $application->branch_id)) {
            $this->denegado($application, $userId, 'Acción reservada al coordinador asignado.');
        }

        $this->exigirSeparacion($application, $userId, ['submitted_by'], 'El capturista no puede evaluar ni corregir su propio expediente.');

        return $user;
    }

    public function exigirVerificador(VerificationVisit $visit, string $userId): User
    {
        $application = $visit->application()->firstOrFail();
        $user = $this->usuarioActivo($userId);

        if ($visit->verifier_id !== $userId
            || ! $this->tieneRolEnSucursal($user, 'verifier', $application->branch_id)) {
            $this->denegado($application, $userId, 'La visita no está asignada al verificador autenticado.');
        }

        $this->exigirSeparacion(
            $application,
            $userId,
            ['submitted_by', 'coordinator_id'],
            'El capturista o coordinador del expediente no puede realizar su verificación.',
        );

        return $user;
    }

    public function exigirGerente(DistributorApplication $application, string $userId): User
    {
        $user = $this->usuarioActivo($userId);
        $global = $this->tieneRolGlobal($user, 'general_manager');
        $sucursal = $this->tieneRolEnSucursal($user, 'branch_manager', $application->branch_id);

        if (! $global && ! $sucursal) {
            $this->denegado($application, $userId, 'El gerente no tiene alcance sobre la sucursal del expediente.');
        }

        $this->exigirSeparacion(
            $application,
            $userId,
            ['submitted_by', 'coordinator_id', 'verifier_id'],
            'Quien capturó, verificó o evaluó el expediente no puede emitir la autorización final.',
        );

        return $user;
    }

    public function puedeConsultar(DistributorApplication $application, string $userId): bool
    {
        $user = $this->usuarioActivo($userId);

        if ($this->tieneRolGlobal($user, 'general_manager') || $this->tieneRolGlobal($user, 'admin')) {
            return true;
        }

        if ($application->coordinator_id === $userId
            && $this->tieneRolEnSucursal($user, 'coordinator', $application->branch_id)) {
            return true;
        }

        if ($application->verifier_id === $userId
            && $this->tieneRolEnSucursal($user, 'verifier', $application->branch_id)) {
            return true;
        }

        return $this->tieneRolEnSucursal($user, 'branch_manager', $application->branch_id);
    }

    public function tieneRolEnSucursal(User $user, string $role, string $branchId): bool
    {
        return $user->roleScopes()
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->where('scope_type', 'BRANCH')
            ->where('branch_id', $branchId)
            ->whereHas('role', fn ($query) => $query->where('code', $role))
            ->exists();
    }

    public function tieneRolGlobal(User $user, string $role): bool
    {
        return $user->roleScopes()
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->where('scope_type', 'GLOBAL')
            ->whereHas('role', fn ($query) => $query->where('code', $role))
            ->exists();
    }

    public function exigirSeparacion(DistributorApplication $application, string $userId, array $fields, string $message): void
    {
        foreach ($fields as $field) {
            if ($application->{$field} !== null && $application->{$field} === $userId) {
                $this->denegado($application, $userId, $message, 'SEGREGATION_OF_DUTIES_VIOLATION');
            }
        }
    }

    private function denegado(
        DistributorApplication $application,
        string $userId,
        string $reason,
        string $code = 'AUTH_SCOPE_DENIED',
    ): never {
        AuditHelper::log(
            'VERIFICATION_ACCESS_DENIED',
            'DistributorApplication',
            $application->id,
            $userId,
            $application->branch_id,
            reason: $reason,
            result: 'DENIED',
            version: $application->lock_version,
        );

        throw new BusinessException($code, $reason, 403);
    }
}
