<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Queries;

use App\Models\User;
use App\Modules\RiskDelinquency\Application\Services\RiskAccessService;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DelinquencyRemovalRequest;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\DistributorRiskProfile;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\RelationRiskEvaluation;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\RiskAlert;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\RiskSequence;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class RiskQueryService
{
    public function __construct(private readonly RiskAccessService $access) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, DistributorRiskProfile>
     */
    public function profiles(User $actor, array $filters): LengthAwarePaginator
    {
        $query = $this->access->scopeProfiles(
            DistributorRiskProfile::query()->with(['distributor', 'branch', 'coordinator']),
            $actor,
        );

        return $query
            ->when($filters['branch_id'] ?? null, fn ($q, $value) => $q->whereHas('branch', fn ($branch) => $branch->where('public_id', $value)))
            ->when($filters['coordinator_id'] ?? null, fn ($q, $value) => $q->whereHas('coordinator', fn ($user) => $user->where('public_id', $value)))
            ->when($filters['distributor_id'] ?? null, fn ($q, $value) => $q->whereHas('distributor', fn ($user) => $user->where('public_id', $value)))
            ->when($filters['delinquency_status'] ?? null, fn ($q, $value) => $q->where('delinquency_status', $value))
            ->when(array_key_exists('financially_regularized', $filters), fn ($q) => $filters['financially_regularized']
                ? $q->whereNotNull('financially_regularized_at')
                : $q->whereNull('financially_regularized_at'))
            ->when(isset($filters['consecutive_breaches']), fn ($q) => $q->where('consecutive_breaches', $filters['consecutive_breaches']))
            ->orderByDesc('updated_at')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function profile(User $actor, User $distributor): DistributorRiskProfile
    {
        $profile = DistributorRiskProfile::query()
            ->with(['distributor', 'branch', 'coordinator'])
            ->where('distributor_id', $distributor->id)
            ->firstOrFail();
        $this->access->assertProfile($actor, $profile);

        return $profile;
    }

    /** @return LengthAwarePaginator<int, RelationRiskEvaluation> */
    public function evaluations(User $actor, User $distributor, int $perPage): LengthAwarePaginator
    {
        $profile = $this->profile($actor, $distributor);
        $this->access->assertDetailedProfile($actor, $profile);

        return RelationRiskEvaluation::query()
            ->where('distributor_id', $profile->distributor_id)
            ->orderByDesc('cut_at')
            ->orderByDesc('due_at')
            ->paginate($perPage);
    }

    public function sequence(User $actor, User $distributor): ?RiskSequence
    {
        $profile = $this->profile($actor, $distributor);
        $this->access->assertDetailedProfile($actor, $profile);

        return RiskSequence::query()
            ->with('relations')
            ->where('distributor_id', $profile->distributor_id)
            ->latest('created_at')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, RiskAlert>
     */
    public function alerts(User $actor, User $distributor, array $filters): LengthAwarePaginator
    {
        $profile = $this->profile($actor, $distributor);
        $this->access->assertDetailedProfile($actor, $profile);

        return RiskAlert::query()
            ->with('relations')
            ->where('distributor_id', $profile->distributor_id)
            ->when($filters['type'] ?? null, fn ($q, $value) => $q->where('alert_type', $value))
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['detected_from'] ?? null, fn ($q, $value) => $q->where('detected_at', '>=', $value))
            ->when($filters['detected_to'] ?? null, fn ($q, $value) => $q->where('detected_at', '<=', $value))
            ->orderByDesc('detected_at')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function alert(User $actor, string $alertNumber): RiskAlert
    {
        $alert = RiskAlert::query()->with('relations')->where('alert_number', $alertNumber)->firstOrFail();
        $profile = DistributorRiskProfile::query()->where('distributor_id', $alert->distributor_id)->firstOrFail();
        $this->access->assertDetailedProfile($actor, $profile);

        return $alert;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, DelinquencyRemovalRequest>
     */
    public function removalRequests(User $actor, array $filters): LengthAwarePaginator
    {
        $this->access->assertRemovalView($actor);
        $visible = $this->access->scopeProfiles(DistributorRiskProfile::query(), $actor)->select('distributor_id');

        return DelinquencyRemovalRequest::query()
            ->whereIn('distributor_id', $visible)
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->orderByDesc('prepared_at')
            ->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function removalRequest(User $actor, string $requestNumber): DelinquencyRemovalRequest
    {
        $request = DelinquencyRemovalRequest::query()->where('request_number', $requestNumber)->firstOrFail();
        $profile = DistributorRiskProfile::query()->where('distributor_id', $request->distributor_id)->firstOrFail();
        $this->access->assertProfile($actor, $profile);
        $this->access->assertRemovalView($actor);

        return $request;
    }
}
