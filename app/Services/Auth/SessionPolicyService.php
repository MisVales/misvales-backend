<?php

namespace App\Services\Auth;

use App\Models\User;
use Carbon\CarbonInterval;

class SessionPolicyService
{
    /**
     * Matriz de políticas por rol (en minutos).
     * [ Access Token, Inactividad, Refresh Token, Duración Absoluta, Reauth MFA ]
     */
    private const POLICIES = [
        'general_manager' => [
            'access_token' => 5,
            'inactivity' => 10,
            'refresh_token' => 8 * 60,
            'absolute' => 8 * 60,
            'mfa_reauth' => 5,
        ],
        'branch_manager' => [
            'access_token' => 5,
            'inactivity' => 15,
            'refresh_token' => 8 * 60,
            'absolute' => 8 * 60,
            'mfa_reauth' => 5,
        ],
        'cashier' => [
            'access_token' => 5,
            'inactivity' => 15,
            'refresh_token' => 8 * 60,
            'absolute' => 8 * 60,
            'mfa_reauth' => 5,
        ],
        'admin' => [
            'access_token' => 10,
            'inactivity' => 20,
            'refresh_token' => 8 * 60,
            'absolute' => 8 * 60,
            'mfa_reauth' => 10,
        ],
        'coordinator' => [
            'access_token' => 10,
            'inactivity' => 20,
            'refresh_token' => 12 * 60,
            'absolute' => 12 * 60,
            'mfa_reauth' => 10,
        ],
        'verifier' => [
            'access_token' => 10,
            'inactivity' => 30,
            'refresh_token' => 12 * 60,
            'absolute' => 12 * 60,
            'mfa_reauth' => 15,
        ],
        'distributor' => [
            'access_token' => 15,
            'inactivity' => 30,
            'refresh_token' => 7 * 24 * 60,
            'absolute' => 7 * 24 * 60,
            'mfa_reauth' => 10,
        ],
    ];

    /**
     * Política por defecto (para usuarios sin rol específico o el rol base más restrictivo).
     */
    private const DEFAULT_POLICY = [
        'access_token' => 5,
        'inactivity' => 10,
        'refresh_token' => 8 * 60,
        'absolute' => 8 * 60,
        'mfa_reauth' => 5,
    ];

    /**
     * Obtiene la política de sesión para un usuario.
     * Si tiene varios roles, escoge el más restrictivo (menor duración).
     */
    public function getPolicyForUser(User $user): array
    {
        $userRoles = $user->roleScopes()->with('role')->get()->pluck('role.code')->toArray();
        
        if (empty($userRoles)) {
            return self::DEFAULT_POLICY;
        }

        $mostRestrictivePolicy = null;

        foreach ($userRoles as $roleCode) {
            $policy = self::POLICIES[$roleCode] ?? self::DEFAULT_POLICY;

            if (!$mostRestrictivePolicy) {
                $mostRestrictivePolicy = $policy;
                continue;
            }

            // Para ser seguros, el más restrictivo es el que tiene los tiempos MENORES
            if ($policy['absolute'] < $mostRestrictivePolicy['absolute'] || 
                ($policy['absolute'] === $mostRestrictivePolicy['absolute'] && $policy['inactivity'] < $mostRestrictivePolicy['inactivity'])) {
                $mostRestrictivePolicy = $policy;
            }
        }

        return $mostRestrictivePolicy;
    }
}
