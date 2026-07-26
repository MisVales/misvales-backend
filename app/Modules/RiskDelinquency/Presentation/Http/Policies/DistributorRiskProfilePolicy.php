<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Presentation\Http\Policies;

use App\Models\User;
use App\Modules\RiskDelinquency\Application\Services\RiskAccessService;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DistributorRiskProfile;

final readonly class DistributorRiskProfilePolicy
{
    public function __construct(private RiskAccessService $access) {}

    public function viewAny(User $actor): bool
    {
        return $this->access->scopeProfiles(DistributorRiskProfile::query(), $actor)->exists();
    }

    public function view(User $actor, DistributorRiskProfile $profile): bool
    {
        return $this->access->scopeProfiles(DistributorRiskProfile::query(), $actor)
            ->whereKey($profile->id)
            ->exists();
    }
}
