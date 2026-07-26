<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCases;

use App\Models\User;
use App\Modules\Reporting\Application\Services\ReportAuthorizationService;
use App\Modules\Reporting\Application\Services\ReportRunService;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportRun;

final readonly class GetReportRun
{
    public function __construct(
        private ReportAuthorizationService $authorization,
        private ReportRunService $runs,
    ) {}

    public function handle(User $actor, string $id): ReportRun
    {
        $this->authorization->assertCatalogAccess($actor);

        return $this->runs->findOwn($actor, $id);
    }
}
