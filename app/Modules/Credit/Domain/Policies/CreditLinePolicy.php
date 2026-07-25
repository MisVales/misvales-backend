<?php

declare(strict_types=1);

namespace App\Modules\Credit\Domain\Policies;

use App\Models\User;
use App\Modules\Credit\Application\Services\CreditScopeService;
use App\Modules\Credit\Domain\Exceptions\CreditRuleViolation;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineModel;

final readonly class CreditLinePolicy
{
    public function __construct(private CreditScopeService $scope) {}

    public function view(User $actor, CreditLineModel $line): bool
    {
        try {
            $this->scope->assertCanReadDistributor($actor, $line->distributor);

            return true;
        } catch (CreditRuleViolation) {
            return false;
        }
    }

    public function viewMovements(User $actor, CreditLineModel $line): bool
    {
        return $this->view($actor, $line);
    }

    public function update(User $actor, CreditLineModel $line): bool
    {
        return false;
    }

    public function delete(User $actor, CreditLineModel $line): bool
    {
        return false;
    }
}
