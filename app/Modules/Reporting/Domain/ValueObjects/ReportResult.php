<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObjects;

use DateTimeImmutable;

final readonly class ReportResult
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $summary
     * @param  array<string, mixed>  $pagination
     */
    public function __construct(
        public array $rows,
        public array $summary,
        public array $pagination,
        public DateTimeImmutable $asOf,
    ) {}
}
