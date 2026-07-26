<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObjects;

use App\Modules\Reporting\Domain\Contracts\ReportScopeInterface;
use App\Modules\Reporting\Domain\Enums\ReportScopeType;

final readonly class ReportScope implements ReportScopeInterface
{
    public function __construct(
        public ReportScopeType $type,
        public ?string $branchId = null,
        public ?string $coordinatorId = null,
        public ?string $distributorId = null,
    ) {}

    public function type(): ReportScopeType
    {
        return $this->type;
    }

    /** @return array{type: string, branch_id?: string, coordinator_id?: string, distributor_id?: string} */
    public function toArray(): array
    {
        $scope = ['type' => $this->type->value];
        if ($this->branchId !== null) {
            $scope['branch_id'] = $this->branchId;
        }
        if ($this->coordinatorId !== null) {
            $scope['coordinator_id'] = $this->coordinatorId;
        }
        if ($this->distributorId !== null) {
            $scope['distributor_id'] = $this->distributorId;
        }

        return $scope;
    }

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value): self
    {
        return new self(
            ReportScopeType::from((string) $value['type']),
            isset($value['branch_id']) ? (string) $value['branch_id'] : null,
            isset($value['coordinator_id']) ? (string) $value['coordinator_id'] : null,
            isset($value['distributor_id']) ? (string) $value['distributor_id'] : null,
        );
    }
}
