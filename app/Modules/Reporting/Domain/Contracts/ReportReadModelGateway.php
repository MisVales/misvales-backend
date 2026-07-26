<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Contracts;

use App\Modules\Reporting\Domain\Enums\ReportCode;
use App\Modules\Reporting\Domain\ValueObjects\ReportResult;
use App\Modules\Reporting\Domain\ValueObjects\ReportScope;

/**
 * Read-only integration boundary implemented by each authoritative owner module.
 */
interface ReportReadModelGateway extends ReportQueryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     */
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
     * Streams deterministic protected blocks for an asynchronous run.
     *
     * Every block must use the same scope, filters and logical as-of moment.
     *
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
