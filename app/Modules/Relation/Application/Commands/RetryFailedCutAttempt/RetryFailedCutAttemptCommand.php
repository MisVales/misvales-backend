<?php

declare(strict_types=1);

namespace App\Modules\Relation\Application\Commands\RetryFailedCutAttempt;

readonly class RetryFailedCutAttemptCommand
{
    public function __construct(
        public string $cutRunId,
        public string $distributorId,
        public string $triggeredBy
    ) {
    }
}
