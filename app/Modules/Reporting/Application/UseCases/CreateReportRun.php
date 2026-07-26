<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCases;

use App\Models\User;
use App\Modules\Reporting\Application\Services\ReportAuthorizationService;
use App\Modules\Reporting\Application\Services\ReportParameterNormalizer;
use App\Modules\Reporting\Application\Services\ReportRegistry;
use App\Modules\Reporting\Application\Services\ReportRunService;
use App\Modules\Reporting\Application\Services\ReportScopeResolver;
use App\Modules\Reporting\Domain\Exceptions\ReportingException;
use App\Modules\Reporting\Infrastructure\Persistence\Models\ReportRun;

final readonly class CreateReportRun
{
    public function __construct(
        private ReportRegistry $registry,
        private ReportAuthorizationService $authorization,
        private ReportScopeResolver $scopes,
        private ReportParameterNormalizer $parameters,
        private ReportRunService $runs,
    ) {}

    /** @param array<string, mixed> $input */
    public function handle(
        User $actor,
        string $code,
        array $input,
        string $idempotencyKey,
        string $correlationId,
    ): ReportRun {
        foreach (['page', 'per_page', 'sort', 'direction'] as $unsupported) {
            if (array_key_exists($unsupported, $input)) {
                throw ReportingException::unsupportedFilter($unsupported);
            }
        }
        $definition = $this->registry->get($code);
        $role = $this->authorization->assertReportAccess($actor, $definition);
        if (! $definition->asynchronous) {
            throw ReportingException::invalidFilter('mode');
        }
        $scope = $this->scopes->resolve($actor, $role);
        $filters = $this->parameters->normalize($definition, $input);
        $this->scopes->assertFiltersCannotExpand($scope, $filters);

        return $this->runs->create(
            $actor,
            $definition,
            $scope,
            $filters,
            $this->parameters->hash($definition, $filters, $scope->toArray()),
            $idempotencyKey,
            $correlationId,
        );
    }
}
