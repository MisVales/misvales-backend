<?php

declare(strict_types=1);

namespace App\Modules\Points\Infrastructure\Jobs;

use App\Modules\Points\Application\Services\PointAccountService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ReconcilePointAccountJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $accountId) {}

    public function handle(PointAccountService $accounts): void
    {
        $accounts->reconcile($this->accountId);
    }
}
