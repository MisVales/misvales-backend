<?php

declare(strict_types=1);

namespace App\Modules\Relation\Interfaces\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Access\Domain\Attributes\CriticalAction;
use App\Modules\Access\Domain\Attributes\PermissionCode;
use App\Modules\Relation\Application\Commands\StartCut\StartCutCommand;
use App\Modules\Relation\Application\Commands\StartCut\StartCutHandler;
use App\Modules\Relation\Application\Queries\GetCutRun\GetCutRunQuery;
use App\Modules\Relation\Infrastructure\Persistence\Models\CutRun;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CutRunController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // $this->authorize('viewAny', CutRun::class);
        $runs = CutRun::orderBy('cut_date', 'desc')->paginate(15);

        return response()->json($runs);
    }

    public function show(string $id, GetCutRunQuery $query): JsonResponse
    {
        $cutRun = $query->handle($id);

        return response()->json($cutRun);
    }

    #[PermissionCode('relations.run-cut')]
    #[CriticalAction]
    public function store(Request $request, StartCutHandler $handler): JsonResponse
    {
        $request->validate([
            'mfa_token' => 'required|string',
            'operative_date' => 'required|date',
        ]);

        $command = new StartCutCommand(
            CarbonImmutable::parse($request->input('operative_date')),
            'AUTHORIZED_RETRY',
            $request->user()?->id ?? 'system'
        );

        $cutRun = $handler->handle($command);

        return response()->json($cutRun, 201);
    }
}
