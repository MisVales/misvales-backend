<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObjects;

use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Reporting\Domain\Contracts\ReportDefinitionInterface;
use App\Modules\Reporting\Domain\Enums\ReportCode;

/**
 * Immutable public contract for one official report.
 */
final readonly class ReportDefinition implements ReportDefinitionInterface
{
    /**
     * @param  list<RoleCode>  $roles
     * @param  list<string>  $sourceModules
     * @param  list<string>  $filters
     * @param  list<string>  $sortableFields
     * @param  list<string>  $columns
     * @param  list<string>  $groupings
     * @param  list<string>  $totals
     * @param  list<string>  $sensitiveFields
     */
    public function __construct(
        public ReportCode $code,
        public string $name,
        public string $description,
        public array $roles,
        public array $sourceModules,
        public array $filters,
        public array $sortableFields,
        public array $columns,
        public array $groupings,
        public array $totals,
        public array $sensitiveFields = [],
        public int $contractVersion = 1,
        public bool $synchronous = true,
        public bool $asynchronous = true,
    ) {}

    public function code(): ReportCode
    {
        return $this->code;
    }

    public function permits(RoleCode $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /** @return array<string, mixed> */
    public function publicContract(): array
    {
        return [
            'code' => $this->code->value,
            'name' => $this->name,
            'description' => $this->description,
            'contract_version' => $this->contractVersion,
            'filters' => $this->filters,
            'sortable_fields' => $this->sortableFields,
            'columns' => $this->columns,
            'groupings' => $this->groupings,
            'totals' => $this->totals,
            'modes' => array_values(array_filter([
                $this->synchronous ? 'SYNCHRONOUS' : null,
                $this->asynchronous ? 'ASYNCHRONOUS' : null,
            ])),
            'actions' => [
                'view' => true,
                'run' => $this->asynchronous,
                'export' => false,
                'download_relation' => false,
            ],
        ];
    }
}
