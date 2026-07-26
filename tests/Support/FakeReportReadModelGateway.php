<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Reporting\Domain\Contracts\ReportReadModelGateway;
use App\Modules\Reporting\Domain\Enums\ReportCode;
use App\Modules\Reporting\Domain\ValueObjects\ReportResult;
use App\Modules\Reporting\Domain\ValueObjects\ReportScope;
use DateTimeImmutable;
use DateTimeZone;

final class FakeReportReadModelGateway implements ReportReadModelGateway
{
    /** @var list<array<string, mixed>> */
    public array $rows = [
        ['distributor' => 'Distribuidora ficticia', 'total' => '100.00', 'available' => '40.00'],
    ];

    public function execute(
        ReportCode $code,
        ReportScope $scope,
        array $filters,
        string $sort,
        string $direction,
        int $page,
        int $perPage,
    ): ReportResult {
        return new ReportResult(
            array_slice($this->rows, ($page - 1) * $perPage, $perPage),
            ['total' => '100.00', 'available' => '40.00'],
            ['page' => $page, 'per_page' => $perPage, 'total' => count($this->rows)],
            new DateTimeImmutable('2026-07-27T00:00:00Z', new DateTimeZone('UTC')),
        );
    }

    public function executeRun(
        ReportCode $code,
        ReportScope $scope,
        array $filters,
        string $sort,
        string $direction,
        int $blockSize,
    ): iterable {
        foreach (array_chunk($this->rows, $blockSize) as $index => $rows) {
            yield new ReportResult(
                $rows,
                ['total' => '100.00', 'available' => '40.00'],
                ['block' => $index],
                new DateTimeImmutable('2026-07-27T00:00:00Z', new DateTimeZone('UTC')),
            );
        }
    }
}
