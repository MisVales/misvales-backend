<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCases;

use App\Models\User;
use App\Modules\Reporting\Application\Services\ReportAuthorizationService;
use App\Modules\Reporting\Application\Services\ReportRunService;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class ListMyReportRuns
{
    public function __construct(
        private ReportAuthorizationService $authorization,
        private ReportRunService $runs,
    ) {}

    /** @return LengthAwarePaginator<int, ReportRun> */
    public function handle(User $actor, int $perPage): LengthAwarePaginator
    {
        $this->authorization->assertCatalogAccess($actor);

        return $this->runs->listOwn($actor, $perPage);
    }
}
