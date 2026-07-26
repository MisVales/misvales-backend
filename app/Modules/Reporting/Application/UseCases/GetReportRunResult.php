<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCases;

use App\Models\User;
use App\Modules\Reporting\Application\Services\ReportAuthorizationService;
use App\Modules\Reporting\Application\Services\ReportRunService;

final readonly class GetReportRunResult
{
    public function __construct(
        private ReportAuthorizationService $authorization,
        private ReportRunService $runs,
    ) {}

    /** @return array{data: list<array<string, mixed>>, meta: array<string, mixed>} */
    public function handle(User $actor, string $id, int $page, int $perPage): array
    {
        $this->authorization->assertCatalogAccess($actor);

        return $this->runs->result($actor, $id, $page, $perPage);
    }
}
