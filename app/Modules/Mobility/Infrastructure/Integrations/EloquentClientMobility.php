<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Infrastructure\Integrations;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Client\Domain\Assignments\AssignmentType;
use App\Modules\Client\Domain\Portfolio\PortfolioBalance;
use App\Modules\Client\Persistence\Models\Client;
use App\Modules\Client\Persistence\Models\ClientDistributorAssignment;
use App\Modules\Client\Persistence\Models\ClientPortfolioConfirmation;
use App\Modules\Client\Persistence\Models\ClientPortfolioEntry;
use App\Modules\Client\Persistence\Models\ClientPortfolioSetting;
use App\Modules\Mobility\Application\Contracts\ClientAssignmentSnapshot;
use App\Modules\Mobility\Application\Contracts\ClientMobilityPort;
use App\Modules\Mobility\Application\Contracts\DistributorSnapshot;
use App\Modules\Mobility\Domain\Exceptions\MobilityException;
use Illuminate\Support\Str;

/** Adaptador M06: conserva el cliente y abre un nuevo tramo histórico. */
final class EloquentClientMobility implements ClientMobilityPort
{
    public function lockAssignment(string $clientId): ClientAssignmentSnapshot
    {
        $client = Client::query()->whereKey($clientId)->lockForUpdate()->first();
        if ($client === null) {
            throw MobilityException::notAssigned();
        }
        $assignment = ClientDistributorAssignment::query()
            ->where('client_id', $clientId)
            ->where('active_slot', true)
            ->lockForUpdate()
            ->first();
        if ($assignment === null) {
            throw MobilityException::notAssigned();
        }
        $setting = ClientPortfolioSetting::query()
            ->where('assignment_id', $assignment->id)
            ->lockForUpdate()
            ->first();
        if ($setting === null) {
            throw MobilityException::dependencyUnavailable('M06_PORTFOLIO');
        }
        $total = PortfolioBalance::calculate(
            ClientPortfolioEntry::query()
                ->where('assignment_id', $assignment->id)
                ->get(['entry_type', 'amount'])
                ->map(static fn ($entry): array => [
                    'entry_type' => $entry->entry_type->value,
                    'amount' => $entry->amount,
                ]),
        );
        $confirmation = ClientPortfolioConfirmation::query()
            ->where('assignment_id', $assignment->id)
            ->where('portfolio_version', $setting->lock_version)
            ->latest('confirmed_at')
            ->lockForUpdate()
            ->first();
        if ($confirmation === null || $confirmation->overdue_balance === null) {
            throw MobilityException::dependencyUnavailable('M06_CONFIRMED_OVERDUE_BALANCE');
        }

        return new ClientAssignmentSnapshot(
            assignmentId: $assignment->id,
            clientId: $client->id,
            clientVersion: (int) $client->lock_version,
            distributorId: $assignment->distributor_id,
            branchId: (int) $assignment->branch_id_snapshot,
            portfolioVersion: (int) $setting->lock_version,
            totalDue: $total,
            overdue: (string) $confirmation->overdue_balance,
        );
    }

    public function distributor(string $publicId): DistributorSnapshot
    {
        $user = User::query()->with('role')->where('public_id', $publicId)->first();
        if ($user === null || $user->role_code !== RoleCode::DISTRIBUTOR->value || $user->branch_id === null) {
            throw MobilityException::invalidRecipient();
        }

        return new DistributorSnapshot(
            id: $user->public_id,
            internalId: $user->id,
            branchId: $user->branch_id,
            coordinatorId: $user->coordinator_id === null ? null : (int) $user->coordinator_id,
            active: $user->state === AccountState::ACTIVE,
        );
    }

    public function lockAssignmentsForClients(array $clientIds): array
    {
        $ids = collect($clientIds)->map(static fn (mixed $id): string => (string) $id)->unique()->sort()->values();
        if ($ids->count() !== count($clientIds)) {
            throw MobilityException::invalidItem(['clients' => ['No se permiten clientes duplicados.']]);
        }

        return $ids->map(fn (string $id): ClientAssignmentSnapshot => $this->lockAssignment($id))->all();
    }

    public function currentAssignmentsForDistributor(string $distributorId): array
    {
        $clientIds = ClientDistributorAssignment::query()
            ->where('distributor_id', $distributorId)
            ->where('active_slot', true)
            ->orderBy('client_id')
            ->pluck('client_id')
            ->all();

        return array_map(fn (string $id): ClientAssignmentSnapshot => $this->lockAssignment($id), $clientIds);
    }

    public function applyAssignment(
        ClientAssignmentSnapshot $current,
        DistributorSnapshot $destination,
        string $operationId,
        string $reason,
        User $actor,
    ): string {
        $assignment = ClientDistributorAssignment::query()
            ->whereKey($current->assignmentId)
            ->where('active_slot', true)
            ->lockForUpdate()
            ->first();
        $client = Client::query()->whereKey($current->clientId)->lockForUpdate()->first();
        if ($assignment === null || $client === null
            || $assignment->distributor_id !== $current->distributorId
            || (int) $client->lock_version !== $current->clientVersion) {
            throw MobilityException::assignmentConflict();
        }
        if (! $this->lockAssignment($current->clientId)->hasZeroBalance()) {
            throw MobilityException::balanceNotZero();
        }

        $now = now();
        $assignment->forceFill([
            'effective_to' => $now,
            'active_slot' => null,
            'reason' => $reason,
            'changed_by' => $actor->id,
        ])->save();

        $next = new ClientDistributorAssignment;
        $next->forceFill([
            'id' => (string) Str::uuid(),
            'client_id' => $current->clientId,
            'distributor_id' => $destination->id,
            'branch_id_snapshot' => $destination->branchId,
            'effective_from' => $now,
            'effective_to' => null,
            'assignment_type' => AssignmentType::AUTHORIZED_TRANSFER,
            'mobility_operation_id' => $operationId,
            'mobility_request_hash' => hash('sha256', implode('|', [
                $current->clientId, $current->distributorId, $destination->id, $reason,
            ])),
            'reason' => $reason,
            'changed_by' => $actor->id,
            'active_slot' => true,
        ])->save();

        $setting = new ClientPortfolioSetting;
        $setting->forceFill([
            'id' => (string) Str::uuid(),
            'client_id' => $current->clientId,
            'distributor_id' => $destination->id,
            'assignment_id' => $next->id,
            'tracking_enabled' => false,
            'lock_version' => 1,
            'updated_by' => $actor->id,
        ])->save();
        $client->forceFill(['lock_version' => $client->lock_version + 1])->save();

        return $next->id;
    }
}
