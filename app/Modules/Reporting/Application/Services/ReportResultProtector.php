<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Services;

use App\Modules\Reporting\Domain\Exceptions\ReportingException;
use App\Modules\Reporting\Domain\ValueObjects\ReportDefinition;
use App\Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * Enforces the public column and total allowlists before serialization or storage.
 */
final class ReportResultProtector
{
    /** @var list<string> */
    private const FORBIDDEN_KEYS = [
        'curp',
        'rfc',
        'bank_account',
        'account_number',
        'password',
        'token',
        'secret',
        'document',
        'photograph',
        'private_notes',
        'full_address',
        'sql',
        'trace',
    ];

    public function protect(ReportDefinition $definition, ReportResult $result): ReportResult
    {
        foreach ($result->rows as $row) {
            if (array_diff(array_keys($row), $definition->columns) !== []) {
                throw ReportingException::dataMinimizationFailed();
            }
            $this->assertNoForbiddenKeys($row);
        }
        if (array_diff(array_keys($result->summary), $definition->totals) !== []) {
            throw ReportingException::dataMinimizationFailed();
        }
        $this->assertNoForbiddenKeys($result->summary);

        return $result;
    }

    /** @param array<string, mixed> $value */
    private function assertNoForbiddenKeys(array $value): void
    {
        foreach ($value as $key => $item) {
            if (in_array(mb_strtolower((string) $key), self::FORBIDDEN_KEYS, true)) {
                throw ReportingException::dataMinimizationFailed();
            }
            if (is_array($item)) {
                $this->assertNoForbiddenKeys($item);
            }
        }
    }
}
