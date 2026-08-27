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

        if (is_string($clientIp)
            && is_array($networks)
            && $networks !== []
            && IpUtils::checkIp($clientIp, $networks)) {
            return true;
        }

        $host = (string) $request->getHost();
        $forwardedHost = (string) $request->header('X-Forwarded-Host', '');
        $origin = (string) $request->headers->get('Origin', '');
        $referer = (string) $request->headers->get('Referer', '');

        $vpnHosts = config('vpn.hosts', ['vpn.safeacces.lat']);

        foreach ([$host, $forwardedHost] as $h) {
            if ($h !== '' && (in_array($h, $vpnHosts, true) || str_starts_with(mb_strtolower($h), 'vpn.'))) {
                return true;
            }
        }

        foreach ([$origin, $referer] as $url) {
            if ($url !== '') {
                $parsedHost = parse_url($url, PHP_URL_HOST);
                if (is_string($parsedHost) && (in_array($parsedHost, $vpnHosts, true) || str_starts_with(mb_strtolower($parsedHost), 'vpn.'))) {
                    return true;
                }
            }
        }

        return false;
    }
}
