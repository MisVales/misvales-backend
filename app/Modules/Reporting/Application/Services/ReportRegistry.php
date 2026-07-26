<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Services;

use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Reporting\Domain\Definitions\OfficialReportDefinitions;
use App\Modules\Reporting\Domain\Enums\ReportCode;
use App\Modules\Reporting\Domain\Exceptions\ReportingException;
use App\Modules\Reporting\Domain\ValueObjects\ReportDefinition;

final class ReportRegistry
{
    /** @var array<string, ReportDefinition> */
    private array $definitions;

    public function __construct(OfficialReportDefinitions $official)
    {
        $this->definitions = [];
        foreach ($official->all() as $definition) {
            if (isset($this->definitions[$definition->code->value])) {
                throw new \LogicException("Duplicate report code {$definition->code->value}.");
            }
            $this->definitions[$definition->code->value] = $definition;
        }
    }

    public function get(ReportCode|string $code): ReportDefinition
    {
        $value = $code instanceof ReportCode ? $code->value : $code;

        return $this->definitions[$value] ?? throw ReportingException::notFound();
    }

    /** @return list<ReportDefinition> */
    public function forRole(RoleCode $role): array
    {
        return array_values(array_filter(
            $this->definitions,
            static fn (ReportDefinition $definition): bool => $definition->permits($role),
        ));
    }

    /** @return list<ReportDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }
}
