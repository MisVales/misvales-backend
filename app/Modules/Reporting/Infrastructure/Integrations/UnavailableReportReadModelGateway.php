<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Integrations;

use App\Modules\Reporting\Domain\Contracts\ReportReadModelGateway;
use App\Modules\Reporting\Domain\Enums\ReportCode;
use App\Modules\Reporting\Domain\Exceptions\ReportingException;
use App\Modules\Reporting\Domain\ValueObjects\ReportResult;
use App\Modules\Reporting\Domain\ValueObjects\ReportScope;

/**
 * Denies execution until owner modules bind authoritative read-only adapters.
 */
final class UnavailableReportReadModelGateway implements ReportReadModelGateway
{
    public function execute(
        ReportCode $code,
        ReportScope $scope,
        array $filters,
        string $sort,
        string $direction,
        int $page,
        int $perPage,
    ): ReportResult {
        throw ReportingException::dependencyUnavailable($code);
    }

    public function executeRun(
        ReportCode $code,
        ReportScope $scope,
        array $filters,
        string $sort,
        string $direction,
        int $blockSize,
    ): iterable {
        throw ReportingException::dependencyUnavailable($code);
    }
}
