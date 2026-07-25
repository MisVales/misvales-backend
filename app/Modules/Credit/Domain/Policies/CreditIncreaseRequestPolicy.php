<?php

declare(strict_types=1);

namespace App\Modules\Credit\Domain\Policies;

use App\Models\User;
use App\Modules\Credit\Application\Services\CreditScopeService;
use App\Modules\Credit\Domain\Exceptions\CreditRuleViolation;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditIncreaseRequestModel;

final readonly class CreditIncreaseRequestPolicy
{
    public function __construct(private CreditScopeService $scope) {}

    public function view(User $actor, CreditIncreaseRequestModel $request): bool
    {
        try {
            $request->loadMissing('distributor');
            $this->scope->assertCanReadDistributor($actor, $request->distributor);

            return true;
        } catch (CreditRuleViolation) {
            return false;
        }
    }

    public function review(User $actor, CreditIncreaseRequestModel $request): bool
    {
        try {
            $this->scope->assertCanReview($actor, $request);

            return true;
        } catch (CreditRuleViolation) {
            return false;
        }
    }

    public function decide(User $actor, CreditIncreaseRequestModel $request): bool
    {
        try {
            $this->scope->assertCanDecide($actor, $request);

            return true;
        } catch (CreditRuleViolation) {
            return false;
        }
    }

    public function delete(User $actor, CreditIncreaseRequestModel $request): bool
    {
        return false;
    }
}
