<?php

namespace App\Http\Middleware;

use App\Services\Security\VpnContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveVpnContext
{
    public const ATTRIBUTE = 'access_context.vpn';

    public function __construct(private readonly VpnContext $vpnContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::ATTRIBUTE, $this->vpnContext->resolve($request));

        return $next($request);
    }
}
