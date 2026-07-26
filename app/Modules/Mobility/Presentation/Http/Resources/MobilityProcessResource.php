<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Presentation\Http\Resources;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Mobility\Domain\Enums\ClientTransferStatus;
use App\Modules\Mobility\Infrastructure\Persistence\Models\AdministrativeReassignment;
use App\Modules\Mobility\Infrastructure\Persistence\Models\ClientTransfer;
use App\Modules\Mobility\Infrastructure\Persistence\Models\CoordinatorReassignmentBatch;
use App\Modules\Mobility\Infrastructure\Persistence\Models\DistributorBranchChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/** @mixin ClientTransfer|AdministrativeReassignment|DistributorBranchChange|CoordinatorReassignmentBatch */
final class MobilityProcessResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $model = $this->resource;
        $actor = $request->user();
        $type = match (true) {
            $model instanceof ClientTransfer => 'CLIENT_TRANSFER',
            $model instanceof AdministrativeReassignment => 'ADMINISTRATIVE_REASSIGNMENT',
            $model instanceof DistributorBranchChange => 'DISTRIBUTOR_BRANCH_CHANGE',
            default => 'COORDINATOR_REASSIGNMENT',
        };
        $folio = $model->transfer_number
            ?? $model->reassignment_number
            ?? $model->change_number
            ?? $model->batch_number;

        return [
            'id' => $model->id,
            'folio' => $folio,
            'type' => $type,
            'status' => $model->status->value,
            'origin' => $this->origin($model),
            'destination' => $this->destination($model),
            'reason' => $model->reason,
            'version' => (int) $model->lock_version,
            'requested_at' => $model->requested_at?->toIso8601String() ?? $model->created_at?->toIso8601String(),
            'completed_at' => $model->completed_at?->toIso8601String(),
            'pending' => $this->pending($model),
            'items' => $this->items($model),
            'available_actions' => $actor instanceof User ? $this->availableActions($model, $actor) : [],
            'history' => $this->when(
                $request->routeIs('api.v1.*.show'),
                fn (): array => DB::table('mobility_state_history')
                    ->where('aggregate_id', $model->id)
                    ->orderBy('occurred_at')
                    ->get(['previous_state', 'new_state', 'use_case', 'occurred_at'])
                    ->map(static fn ($row): array => (array) $row)->all(),
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function origin(object $model): array
    {
        return array_filter([
            'distributor_id' => $model->origin_distributor_id ?? $model->distributor_id ?? null,
            'branch_id' => $model->origin_branch_id ?? $model->scope_branch_id ?? $model->branch_id ?? null,
            'coordinator_id' => $model->origin_coordinator_id ?? $model->outgoing_coordinator_id ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private function destination(object $model): array
    {
        return array_filter([
            'distributor_id' => $model->recipient_distributor_id ?? null,
            'branch_id' => $model->destination_branch_id ?? null,
            'coordinator_id' => $model->destination_coordinator_id ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, int|bool> */
    private function pending(object $model): array
    {
        return match (true) {
            $model instanceof AdministrativeReassignment => [
                'items' => $model->relationLoaded('items') ? $model->items->where('status', '!=', 'COMPLETED')->count() : 0,
            ],
            $model instanceof DistributorBranchChange => [
                'clients' => $model->relationLoaded('clientItems') ? $model->clientItems->where('status', '!=', 'COMPLETED')->count() : 0,
                'destination_coordinator' => $model->destination_coordinator_id === null,
            ],
            $model instanceof CoordinatorReassignmentBatch => [
                'distributors' => $model->relationLoaded('items') ? $model->items->whereNull('destination_coordinator_id')->count() : 0,
            ],
            default => [],
        };
    }

    /** @return list<array<string, mixed>> */
    private function items(object $model): array
    {
        $items = match (true) {
            $model instanceof AdministrativeReassignment && $model->relationLoaded('items') => $model->items,
            $model instanceof DistributorBranchChange && $model->relationLoaded('clientItems') => $model->clientItems,
            $model instanceof CoordinatorReassignmentBatch && $model->relationLoaded('items') => $model->items,
            default => collect(),
        };

        return $items->map(static fn ($item): array => array_filter([
            'id' => $item->id,
            'client_id' => $item->client_id ?? null,
            'distributor_id' => $item->distributor_id ?? null,
            'origin_distributor_id' => $item->origin_distributor_id ?? null,
            'destination_distributor_id' => $item->destination_distributor_id ?? null,
            'destination_coordinator_id' => $item->destination_coordinator_id ?? null,
            'status' => $item->status,
            'error_code' => $item->error_code ?? null,
        ], static fn (mixed $value): bool => $value !== null))->values()->all();
    }

    /** @return list<string> */
    private function availableActions(object $model, User $actor): array
    {
        if ($model instanceof ClientTransfer) {
            return match ($model->status) {
                ClientTransferStatus::REQUESTED => $actor->public_id === $model->recipient_distributor_id
                    ? ['preaccept', 'preaccept-rejection'] : [],
                ClientTransferStatus::PREACCEPTED => $actor->id === $model->origin_coordinator_id
                    ? ['origin-decision'] : [],
                ClientTransferStatus::ORIGIN_EXIT_AUTHORIZED => $actor->public_id === $model->recipient_distributor_id
                    ? ['final-acceptance'] : [],
                default => [],
            };
        }
        if ($actor->role_code === RoleCode::ADMINISTRATOR->value) {
            return [];
        }

        return match (true) {
            $model instanceof AdministrativeReassignment => match ($model->status->value) {
                'DRAFT', 'REJECTED_BY_VALIDATION' => ['validate'],
                'VALIDATED' => ['complete'],
                default => [],
            },
            $model instanceof DistributorBranchChange => match ($model->status->value) {
                'REQUESTED' => ['authorize'],
                'CLIENT_REASSIGNMENT_PENDING' => ['client-destinations'],
                'DESTINATION_COORDINATOR_PENDING' => ['destination-coordinator'],
                'READY_TO_COMPLETE' => ['complete'],
                default => [],
            },
            $model instanceof CoordinatorReassignmentBatch => match ($model->status->value) {
                'ASSIGNMENT_PENDING' => ['assignments', 'validate'],
                'READY_TO_COMPLETE' => ['validate', 'complete'],
                default => [],
            },
            default => [],
        };
    }
}
