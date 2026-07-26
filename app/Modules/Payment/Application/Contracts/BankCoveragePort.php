<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Contracts;

/** Confirma que una importación procesada cubre completamente sucursal y fecha operativa. */
interface BankCoveragePort
{
    public function processedImportIdFor(int $branchId, string $businessDate): ?string;
}
