<?php

declare(strict_types=1);

namespace App\Modules\Relation\Application\Contracts;

use Carbon\CarbonImmutable;

interface ConfigurationSnapshotProvider
{
    /**
     * Resolves the required configuration versions for the cut run.
     * Includes days after cut, early payment period, bank details, engine version.
     *
     * @param CarbonImmutable $operativeDate
     * @return array
     */
    public function resolveSnapshot(CarbonImmutable $operativeDate): array;
}
