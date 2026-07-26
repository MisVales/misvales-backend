<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Contracts;

use App\Modules\Reporting\Domain\Enums\ReportScopeType;

interface ReportScopeInterface
{
    public function type(): ReportScopeType;

    /** @return array<string, string> */
    public function toArray(): array;
}
