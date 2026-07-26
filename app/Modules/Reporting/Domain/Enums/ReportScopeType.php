<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Enums;

enum ReportScopeType: string
{
    case GLOBAL = 'GLOBAL';
    case BRANCH = 'BRANCH';
    case COORDINATOR = 'COORDINATOR';
    case DISTRIBUTOR = 'DISTRIBUTOR';
}
