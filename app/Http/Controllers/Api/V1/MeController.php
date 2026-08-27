<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Auth\SessionContextService;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * GET /api/v1/me
     * Devuelve la identidad del usuario, alcances y permisos efectivos.
     */
    public function show(Request $request, SessionContextService $sessionContext)
    {
        return response()->json($sessionContext->for($request->user(), $request));
    }
}
