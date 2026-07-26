<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Contracts;

use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Reporting\Domain\Enums\ReportCode;

interface ReportDefinitionInterface
{
    public function code(): ReportCode;

    public function permits(RoleCode $role): bool;

    /** @return array<string, mixed> */
    public function publicContract(): array;
}
