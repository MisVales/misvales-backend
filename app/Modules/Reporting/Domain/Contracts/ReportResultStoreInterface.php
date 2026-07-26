<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Contracts;

use App\Modules\Reporting\Domain\ValueObjects\ReportResult;

interface ReportResultStoreInterface
{
    public function storeBlock(string $runId, int $blockNumber, ReportResult $result): void;

    public function purge(string $runId): void;
}
