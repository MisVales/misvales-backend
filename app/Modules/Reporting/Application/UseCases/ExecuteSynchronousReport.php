<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\UseCases;

use App\Models\User;
use App\Modules\Reporting\Application\Services\ReportAuditRecorder;
use App\Modules\Reporting\Application\Services\ReportAuthorizationService;
use App\Modules\Reporting\Application\Services\ReportParameterNormalizer;
use App\Modules\Reporting\Application\Services\ReportRegistry;
use App\Modules\Reporting\Application\Services\ReportResultProtector;
use App\Modules\Reporting\Application\Services\ReportScopeResolver;
use App\Modules\Reporting\Domain\Contracts\ReportReadModelGateway;
use App\Modules\Reporting\Domain\Exceptions\ReportingException;

final readonly class ExecuteSynchronousReport
{
    public function __construct(
        private ReportRegistry $registry,
        private ReportAuthorizationService $authorization,
        private ReportScopeResolver $scopes,
        private ReportParameterNormalizer $parameters,
        private ReportReadModelGateway $gateway,
        private ReportAuditRecorder $audit,
        private ReportResultProtector $protector,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function handle(User $actor, string $code, array $input, string $correlationId): array
    {
        $definition = $this->registry->get($code);
        $role = $this->authorization->assertReportAccess($actor, $definition);
        if (! $definition->synchronous) {
            throw ReportingException::synchronousLimitExceeded();
        }
        $scope = $this->scopes->resolve($actor, $role);
        $filters = $this->parameters->normalize($definition, $input);
        $this->scopes->assertFiltersCannotExpand($scope, $filters);

        $sort = (string) ($input['sort'] ?? $definition->sortableFields[0]);
        if (! in_array($sort, $definition->sortableFields, true)) {
            throw ReportingException::unsupportedSort();
        }
        $direction = strtolower((string) ($input['direction'] ?? 'asc'));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw ReportingException::unsupportedSort();
        }
        $page = $this->positiveInteger($input['page'] ?? 1, 'page');
        $perPage = $this->positiveInteger($input['per_page'] ?? config('reporting.default_page_size', 25), 'per_page');
        if ($perPage > (int) config('reporting.maximum_page_size', 100)) {
            throw ReportingException::invalidFilter('per_page');
        }

        $result = $this->protector->protect($definition, $this->gateway->execute(
            $definition->code,
            $scope,
            $filters,
            $sort,
            $direction,
            $page,
            $perPage,
        ));
        $this->audit->allowed($actor, $definition, $scope, $filters, count($result->rows), $correlationId);

        return [
            'data' => $result->rows,
            'meta' => [
                'report_code' => $definition->code->value,
                'contract_version' => $definition->contractVersion,
                'generated_at' => now((string) config('reporting.business_timezone'))->toIso8601String(),
                'timezone' => config('reporting.business_timezone'),
                'as_of' => $result->asOf->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                'filters' => $filters,
                'scope' => $scope->toArray(),
                'pagination' => $result->pagination,
                'summary' => $result->summary,
            ],
        ];
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($integer) ? $integer : throw ReportingException::invalidFilter($field);
    }
}
