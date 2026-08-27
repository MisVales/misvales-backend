<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\Security\VpnContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireVpn
{
    private const MANAGER_ROLES = ['general_manager', 'branch_manager'];

    public function __construct(private readonly VpnContext $vpnContext) {}

    public function handle(Request $request, Closure $next, string $mode = 'manager-write'): Response
    {
        $user = $request->user();
        $isManager = $user !== null
            && collect(self::MANAGER_ROLES)->contains(fn (string $role): bool => $user->hasRole($role));
        $vpn = $this->vpnContext->resolve($request);

        $mustUseVpn = $mode === 'always' || ($isManager && ! $request->isMethodSafe());

        if ($mustUseVpn && ! $vpn) {
            throw new ApiException(
                'VPN_REQUIRED',
                'Esta acción gerencial requiere conexión a la VPN.',
                403
            );
        }

        return $next($request);
    }
}
