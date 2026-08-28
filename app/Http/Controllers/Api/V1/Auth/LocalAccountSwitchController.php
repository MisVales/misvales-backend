<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AuthSession;
use App\Models\Distribuidora;
use App\Models\User;
use App\Services\Auth\SessionPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class LocalAccountSwitchController extends Controller
{
    private const DEMO_ROLES = [
        'admin',
        'general_manager',
        'branch_manager',
        'coordinator',
        'verifier',
        'cashier',
    ];

    public function index(): JsonResponse
    {
        $this->assertEnabled();

        $accounts = User::query()
            ->where('state', 'ACTIVE')
            ->whereRaw('lower(email) <> lower(?)', [(string) config('bootstrap.local_super_session.email')])
            ->whereHas('roleScopes', fn ($query) => $query
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->whereHas('role', fn ($role) => $role->whereIn('code', self::DEMO_ROLES)))
            ->with(['roleScopes' => fn ($query) => $query
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->whereHas('role', fn ($role) => $role->whereIn('code', self::DEMO_ROLES))
                ->with('role:id,code,name')])
            ->orderBy('name')
            ->get()
            ->map(function (User $user): array {
                $scope = $user->roleScopes->first();

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role_code' => $scope?->role?->code,
                    'role_name' => $scope?->role?->name,
                ];
            })
            ->sortBy(fn (array $account): int => (int) array_search($account['role_code'], self::DEMO_ROLES, true))
            ->values();

        $distributors = Distribuidora::query()
            ->where('status', 'ACTIVE')
            ->whereNotNull('activated_at')
            ->whereHas('usuario', fn ($query) => $query->where('state', 'ACTIVE'))
            ->with('usuario:id,name,email')
            ->orderBy('distributor_number')
            ->get()
            ->map(fn (Distribuidora $distributor): array => [
                'id' => $distributor->user_id,
                'name' => $distributor->usuario->name,
                'email' => $distributor->usuario->email,
                'role_code' => 'distributor',
                'role_name' => 'Distribuidora',
                'distributor_number' => $distributor->distributor_number,
            ])
            ->values();

        return response()->json(['data' => compact('accounts', 'distributors')]);
    }

    public function store(Request $request, SessionPolicyService $policyService): JsonResponse
    {
        $this->assertEnabled();
        $validated = $request->validate(['user_id' => ['required', 'uuid']]);

        $target = User::query()->whereKey($validated['user_id'])->where('state', 'ACTIVE')->first();
        if (! $target || ! $this->isSelectable($target)) {
            throw new ApiException('LOCAL_ACCOUNT_NOT_AVAILABLE', 'La cuenta local seleccionada no está disponible.', 404);
        }

        return DB::transaction(function () use ($request, $target, $policyService): JsonResponse {
            $currentUser = $request->user();
            $currentToken = $currentUser?->currentAccessToken();

            if ($currentToken && $currentUser) {
                AuthSession::query()
                    ->where('session_identifier_hash', (string) $currentToken->getRawOriginal('token'))
                    ->whereNull('revoked_at')
                    ->update([
                        'revoked_at' => now(),
                        'revoked_by_user_id' => $currentUser->id,
                        'revocation_reason' => 'LOCAL_ACCOUNT_SWITCH',
                    ]);
                $currentToken->delete();
            }

            $policy = $policyService->getPolicyForUser($target);
            $token = $target->createToken('local_account_switch_'.Str::random(10));
            $token->accessToken->expires_at = now()->addMinutes($policy['access_token']);
            $token->accessToken->save();

            AuthSession::query()->create([
                'user_id' => $target->id,
                'session_identifier_hash' => (string) $token->accessToken->getRawOriginal('token'),
                'authentication_method' => 'LOCAL_ACCOUNT_SWITCH',
                'mfa_method' => 'LOCAL_BYPASS',
                'mfa_verified_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'device_name' => 'Selector de cuenta local',
                'last_activity_at' => now(),
                'expires_at' => now()->addMinutes($policy['absolute']),
            ]);

            return response()->json([
                'message' => 'Cuenta local cambiada.',
                'access_token' => $token->plainTextToken,
                'expires_in' => $policy['access_token'] * 60,
                'user' => [
                    'id' => $target->id,
                    'name' => $target->name,
                    'email' => $target->email,
                ],
            ]);
        });
    }

    private function isSelectable(User $user): bool
    {
        $hasDemoRole = $user->roleScopes()
            ->where('status', 'ACTIVE')
            ->whereNull('revoked_at')
            ->whereHas('role', fn ($query) => $query->whereIn('code', self::DEMO_ROLES))
            ->exists();

        return $hasDemoRole || $user->distribuidora()
            ->where('status', 'ACTIVE')
            ->whereNotNull('activated_at')
            ->exists();
    }

    private function assertEnabled(): void
    {
        if (! app()->environment('local')) {
            throw new ApiException('NOT_FOUND', 'Recurso no encontrado.', 404);
        }
    }
}
