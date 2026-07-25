<?php

declare(strict_types=1);

namespace App\Modules\Credit\Application\Queries;

use App\Models\User;
use App\Modules\Credit\Application\Services\CreditQueryService;

final readonly class GetCreditLine
{
    public function __construct(private CreditQueryService $queries) {}

    /** @return array<string, mixed> */
    public function handle(User $actor, User $distributor): array
    {
        return $this->queries->summary($actor, $distributor);
    }
}
