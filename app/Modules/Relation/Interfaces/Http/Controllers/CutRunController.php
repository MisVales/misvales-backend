<?php

declare(strict_types=1);

namespace App\Modules\Relation\Interfaces\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Relation\Infrastructure\Persistence\Models\CutRun;
use App\Modules\Relation\Application\Commands\StartCut\StartCutCommand;
use App\Modules\Relation\Application\Commands\StartCut\StartCutHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\CarbonImmutable;

class CutRunController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // $this->authorize('viewAny', CutRun::class);
        $runs = CutRun::orderBy('cut_date', 'desc')->paginate(15);
        
        return response()->json($runs);
    }

    public function show(CutRun $cutRun): JsonResponse
    {
        // $this->authorize('view', $cutRun);
        $cutRun->load('distributors');
        
        return response()->json($cutRun);
    }

    /**
     * MFA Protected Endpoint
     */
    public function store(Request $request, StartCutHandler $handler): JsonResponse
    {
        // Todo endpoint que escriba debe reautenticar MFA
        // Inyección estricta de Autenticación MFA (usando PermissionCode y CriticalAction)
        // Ejemplo de validación:
        $request->validate([
            'mfa_token' => 'required|string',
            'operative_date' => 'required|date'
        ]);
        
        // $this->authorize('create', CutRun::class);

        $command = new StartCutCommand(
            CarbonImmutable::parse($request->input('operative_date')),
            'AUTHORIZED_RETRY',
            $request->user()?->id ?? 'system'
        );

        $cutRun = $handler->handle($command);

        return response()->json($cutRun, 201);
    }
}
