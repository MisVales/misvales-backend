<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Contracts;

use App\Modules\Reporting\Domain\Enums\ReportCode;
use App\Modules\Reporting\Domain\ValueObjects\ReportResult;
use App\Modules\Reporting\Domain\ValueObjects\ReportScope;

interface ReportQueryInterface
{
    /** @param array<string, mixed> $filters */
    public function execute(
        ReportCode $code,
        ReportScope $scope,
        array $filters,
        string $sort,
        string $direction,
        int $page,
        int $perPage,
    ): ReportResult;

    /**
     * @param  array<string, mixed>  $filters
     * @return iterable<ReportResult>
     */
    public function executeRun(
        ReportCode $code,
        ReportScope $scope,
        array $filters,
        string $sort,
        string $direction,
        int $blockSize,
    ): iterable;
}
