<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCases;

use App\Models\User;
use App\Modules\Reporting\Application\Services\ReportAuthorizationService;
use App\Modules\Reporting\Application\Services\ReportRegistry;
use App\Modules\Reporting\Domain\ValueObjects\ReportDefinition;

final readonly class ListAvailableReports
{
    public function __construct(
        private ReportAuthorizationService $authorization,
        private ReportRegistry $registry,
    ) {}

    /** @return list<ReportDefinition> */
    public function handle(User $actor): array
    {
        return $this->registry->forRole($this->authorization->assertCatalogAccess($actor));
    }
}
