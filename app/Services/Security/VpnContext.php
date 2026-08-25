<?php

namespace App\Services\Security;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

final class VpnContext
{
    public function resolve(Request $request): bool
    {
        $clientIp = $request->ip();
        $networks = config('vpn.networks', []);

        return is_string($clientIp)
            && is_array($networks)
            && $networks !== []
            && IpUtils::checkIp($clientIp, $networks);
    }
}
