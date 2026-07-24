<?php

namespace App\Modules\Access\Application\Context;

use App\Models\User;
use App\Modules\Access\Domain\Context\EffectiveContext;

final class EffectiveContextBuilder
{
    private const EXPERIENCES = [
        'GENERAL_MANAGER' => ['code' => 'ADMIN', 'layout' => 'desktop', 'route' => '/administracion/inicio'],
        'SUCURSAL_MANAGER' => ['code' => 'ADMIN', 'layout' => 'desktop', 'route' => '/administracion/inicio'],
        'CASHIER' => ['code' => 'ADMIN', 'layout' => 'desktop', 'route' => '/administracion/inicio'],
        'ADMINISTRATOR' => ['code' => 'ADMIN', 'layout' => 'desktop', 'route' => '/administracion/inicio'],
        'COORDINATOR' => ['code' => 'TABLET', 'layout' => 'tablet', 'route' => '/tableta/inicio'],
        'VERIFIER' => ['code' => 'TABLET', 'layout' => 'tablet', 'route' => '/tableta/inicio'],
        'DISTRIBUTOR' => ['code' => 'DISTRIBUTOR_MOBILE', 'layout' => 'mobile', 'route' => '/distribuidora/inicio'],
    ];

    /** @param array<string, mixed>|null $sessionData */
    public function build(User $user, ?array $sessionData = null): EffectiveContext
    {
        $user->loadMissing(['role.permissions', 'branch', 'coordinator']);
        $roleCode = $user->role->code->value;
        $experience = self::EXPERIENCES[$roleCode];

        return new EffectiveContext(
            user: [
                'id' => $user->public_id,
                'email' => $user->normalized_email,
                'displayName' => $user->name,
                'status' => $user->state->value,
            ],
            role: [
                'code' => $roleCode,
                'name' => $user->role->name,
            ],
            scope: [
                'type' => $user->role->code->isGlobal() ? 'GLOBAL' : 'BRANCH',
                'branchId' => $user->branch?->public_id,
            ],
            permissions: $user->role->permissions->pluck('code')->sort()->values()->all(),
            hierarchy: [
                'coordinatorId' => $user->coordinator?->public_id,
                'assignmentVersion' => $user->assignment_version,
            ],
            experience: [
                'code' => $experience['code'],
                'layout' => $experience['layout'],
                'homeRoute' => $experience['route'],
            ],
            session: [
                'id' => $sessionData['id'] ?? null,
                'authenticatedAt' => $sessionData['created_at'] ?? now()->toIso8601String(),
                'assuranceLevel' => 'PASSWORD_MFA',
                'reauthenticatedUntil' => null,
            ],
            contextVersion: $user->context_version,
        );
    }
}
