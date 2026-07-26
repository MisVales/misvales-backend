<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Reporting\Application\UseCases\CreateReportRun;
use App\Modules\Reporting\Application\UseCases\ExecuteSynchronousReport;
use App\Modules\Reporting\Application\UseCases\GetReportDefinition;
use App\Modules\Reporting\Application\UseCases\GetReportRun;
use App\Modules\Reporting\Application\UseCases\GetReportRunResult;
use App\Modules\Reporting\Application\UseCases\ListAvailableReports;
use App\Modules\Reporting\Application\UseCases\ListMyReportRuns;
use App\Modules\Reporting\Presentation\Http\Requests\CreateReportRunRequest;
use App\Modules\Reporting\Presentation\Http\Requests\ReportQueryRequest;
use App\Modules\Reporting\Presentation\Http\Requests\ReportRunsIndexRequest;
use App\Modules\Reporting\Presentation\Http\Resources\ReportDefinitionResource;
use App\Modules\Reporting\Presentation\Http\Resources\ReportRunResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

final class ReportController extends Controller
{
    public function __construct(
        private readonly ListAvailableReports $listReports,
        private readonly GetReportDefinition $getDefinition,
        private readonly ExecuteSynchronousReport $execute,
        private readonly CreateReportRun $createRun,
        private readonly ListMyReportRuns $listRuns,
        private readonly GetReportRun $getRun,
        private readonly GetReportRunResult $getResult,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return ReportDefinitionResource::collection($this->listReports->handle($this->actor($request)));
    }

    public function definition(Request $request, string $code): ReportDefinitionResource
    {
        return new ReportDefinitionResource($this->getDefinition->handle($this->actor($request), $code));
    }

    public function execute(ReportQueryRequest $request, string $code): JsonResponse
    {
        return response()->json($this->execute->handle(
            $this->actor($request),
            $code,
            $request->query(),
            $this->correlation($request),
        ));
    }

    public function createRun(CreateReportRunRequest $request, string $code): JsonResponse
    {
        $run = $this->createRun->handle(
            $this->actor($request),
            $code,
            $request->all(),
            $request->idempotencyKey(),
            $this->correlation($request),
        );

        return response()->json(['data' => (new ReportRunResource($run))->resolve($request)], $run->wasRecentlyCreated ? 202 : 200);
    }

    public function runs(ReportRunsIndexRequest $request): AnonymousResourceCollection
    {
        return ReportRunResource::collection($this->listRuns->handle(
            $this->actor($request),
            (int) $request->input('per_page', config('reporting.default_page_size', 25)),
        ));
    }

    public function run(Request $request, string $run): ReportRunResource
    {
        return new ReportRunResource($this->getRun->handle($this->actor($request), $run));
    }

    public function results(ReportRunsIndexRequest $request, string $run): JsonResponse
    {
        return response()->json($this->getResult->handle(
            $this->actor($request),
            $run,
            (int) $request->input('page', 1),
            (int) $request->input('per_page', config('reporting.default_page_size', 25)),
        ));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function correlation(Request $request): string
    {
        $value = (string) $request->header('X-Request-Id', '');

        return Str::isUuid($value) ? $value : (string) Str::uuid();
    }
}
