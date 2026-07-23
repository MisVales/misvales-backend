<?php

namespace App\Modules\Access\Application\Context;

use App\Models\User;
use App\Modules\Access\Domain\Context\EffectiveContext;
use Illuminate\Support\Facades\Request;

class EffectiveContextBuilder
{
    private const ROLES = [
        'GENERAL_MANAGER' => ['name' => 'Gerente general', 'experience' => 'ADMIN', 'route' => '/administracion/inicio'],
        'BRANCH_MANAGER' => ['name' => 'Gerente de sucursal', 'experience' => 'ADMIN', 'route' => '/administracion/inicio'],
        'CASHIER' => ['name' => 'Cajera', 'experience' => 'ADMIN', 'route' => '/administracion/inicio'],
        'ADMIN' => ['name' => 'Administrador', 'experience' => 'ADMIN', 'route' => '/administracion/inicio'],
        'COORDINATOR' => ['name' => 'Coordinador', 'experience' => 'TABLET', 'route' => '/tableta/inicio'],
        'VERIFIER' => ['name' => 'Verificador', 'experience' => 'TABLET', 'route' => '/tableta/inicio'],
        'DISTRIBUTOR' => ['name' => 'Distribuidora', 'experience' => 'DISTRIBUTOR_MOBILE', 'route' => '/distribuidora/inicio'],
    ];

    private const ROLE_PERMISSIONS = [
        'BRANCH_MANAGER' => [
            'accounts.branch.request',
            'accounts.branch.view',
            // Agregaremos más en B08 y módulos siguientes
        ],
        // Definir permisos básicos de B08 para pruebas
        'ADMIN' => [
            'global.view',
            'audit.view'
        ]
    ];

    public function build(User $user, ?array $sessionData = null): EffectiveContext
    {
        $roleCode = $user->role_code ?? 'DISTRIBUTOR';
        $roleDef = self::ROLES[$roleCode] ?? self::ROLES['DISTRIBUTOR'];

        $role = [
            'code' => $roleCode,
            'name' => $roleDef['name'],
        ];

        $scope = [
            'type' => $roleCode === 'GENERAL_MANAGER' || $roleCode === 'ADMIN' ? 'GLOBAL' : 'BRANCH',
            'branchId' => $user->branch_id,
        ];

        $permissions = self::ROLE_PERMISSIONS[$roleCode] ?? [];

        $hierarchy = [
            'coordinatorId' => $user->coordinator_id ? (string) $user->coordinator_id : null,
            'assignmentVersion' => $user->assignment_version,
        ];

        $experience = [
            'code' => $roleDef['experience'],
            'layout' => strtolower($roleDef['experience']) === 'distributor_mobile' ? 'mobile' : (strtolower($roleDef['experience']) === 'tablet' ? 'tablet' : 'desktop'),
            'homeRoute' => $roleDef['route'],
        ];

        $session = [
            'id' => $sessionData['id'] ?? null,
            'authenticatedAt' => $sessionData['created_at'] ?? now()->toIso8601String(),
            'assuranceLevel' => 'PASSWORD_MFA',
            'reauthenticatedUntil' => null,
        ];

        return new EffectiveContext(
            user: [
                'id' => $user->public_id,
                'email' => $user->normalized_email,
                'displayName' => $user->name, // Assuming name column exists
                'status' => $user->state,
            ],
            role: $role,
            scope: $scope,
            permissions: $permissions,
            hierarchy: $hierarchy,
            experience: $experience,
            session: $session,
            contextVersion: $user->context_version
        );
    }
}
