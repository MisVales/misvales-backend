<?php

declare(strict_types=1);

namespace App\Modules\Credit\Application\Commands;

use App\Modules\Credit\Application\DTOs\InitialCreditAuthorization;
use App\Modules\Credit\Application\Services\InitialCreditLineService;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineModel;

final readonly class RegisterInitialCreditLine
{
    public function __construct(private InitialCreditLineService $service) {}

    public function handle(InitialCreditAuthorization $command): CreditLineModel
    {
        return $this->service->register($command);
    }
}
