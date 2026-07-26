<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Services;

use App\Modules\Reporting\Domain\Exceptions\ReportingException;

final class ExactDecimalAggregator
{
    /** @param iterable<string> $amounts */
    public function sum(iterable $amounts, int $outputScale = 2, int $workingScale = 4): string
    {
        $total = '0';
        foreach ($amounts as $amount) {
            if (! preg_match('/^-?\d+(?:\.\d+)?$/', $amount)) {
                throw ReportingException::invalidFilter('amount');
            }
            $total = bcadd($total, $amount, $workingScale);
        }

        return bcadd($total, '0', $outputScale);
    }
}
