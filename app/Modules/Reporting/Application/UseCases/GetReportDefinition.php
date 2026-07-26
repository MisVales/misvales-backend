<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCases;

use App\Models\User;
use App\Modules\Reporting\Application\Services\ReportAuthorizationService;
use App\Modules\Reporting\Application\Services\ReportRegistry;
use App\Modules\Reporting\Domain\ValueObjects\ReportDefinition;

final readonly class GetReportDefinition
{
    public function __construct(
        private ReportAuthorizationService $authorization,
        private ReportRegistry $registry,
    ) {}

    public function handle(User $actor, string $code): ReportDefinition
    {
        $definition = $this->registry->get($code);
        $this->authorization->assertReportAccess($actor, $definition);

        return $definition;
    }
}
