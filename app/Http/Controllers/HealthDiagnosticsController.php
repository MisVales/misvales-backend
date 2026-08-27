<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

final class HealthDiagnosticsController
{
    public function __invoke(Request $request): View
    {
        return view('health-diagnostics', [
            'diagnostics' => [
                'IP resuelta por Laravel' => $request->ip(),
                'IP reportada por Cloudflare' => $request->header('CF-Connecting-IP'),
                'Cadena de proxy' => $request->header('X-Forwarded-For'),
                'User-Agent' => $request->userAgent(),
                'Origen' => $request->header('Origin'),
                'Referente' => $request->header('Referer'),
                'Host solicitado' => $request->getHost(),
                'Protocolo' => $request->getScheme(),
                'Método' => $request->method(),
                'Fecha del servidor' => now()->toIso8601String(),
            ],
        ]);
    }
}
