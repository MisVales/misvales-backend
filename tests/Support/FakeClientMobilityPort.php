<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Mobility\Application\Contracts\ClientAssignmentSnapshot;
use App\Modules\Mobility\Application\Contracts\ClientMobilityPort;
use App\Modules\Mobility\Application\Contracts\DistributorSnapshot;
use App\Modules\Mobility\Domain\Exceptions\MobilityException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class FakeClientMobilityPort implements ClientMobilityPort
{
    /** @var array<string, ClientAssignmentSnapshot> */
    public array $assignments = [];

    /** @var array<string, DistributorSnapshot> */
    public array $distributors = [];

    public int $applied = 0;

    public function addAssignment(ClientAssignmentSnapshot $snapshot): void
    {
        $this->assignments[$snapshot->clientId] = $snapshot;
    }

    public function addDistributor(DistributorSnapshot $snapshot): void
    {
        $this->distributors[$snapshot->id] = $snapshot;
    }

    public function lockAssignment(string $clientId): ClientAssignmentSnapshot
    {
        return $this->assignments[$clientId] ?? throw MobilityException::notAssigned();
    }

    public function distributor(string $publicId): DistributorSnapshot
    {
        return $this->distributors[$publicId] ?? throw MobilityException::invalidRecipient();
    }

    public function lockAssignmentsForClients(array $clientIds): array
    {
        if (count($clientIds) !== count(array_unique($clientIds))) {
            throw MobilityException::invalidItem();
        }

        return array_map(fn (string $id): ClientAssignmentSnapshot => $this->lockAssignment($id), $clientIds);
    }

    public function currentAssignmentsForDistributor(string $distributorId): array
    {
        return array_values(array_filter(
            $this->assignments,
            static fn (ClientAssignmentSnapshot $snapshot): bool => $snapshot->distributorId === $distributorId,
        ));
    }

    public function applyAssignment(
        ClientAssignmentSnapshot $current,
        DistributorSnapshot $destination,
        string $operationId,
        string $reason,
        User $actor,
    ): string {
        $fresh = $this->lockAssignment($current->clientId);
        if ($fresh->assignmentId !== $current->assignmentId || ! $fresh->hasZeroBalance()) {
            throw MobilityException::assignmentConflict();
        }
        $id = (string) Str::uuid();
        if (Schema::hasTable('client_distributor_assignments')
            && DB::table('client_distributor_assignments')->where('id', $current->assignmentId)->exists()) {
            DB::table('client_distributor_assignments')->where('id', $current->assignmentId)->update([
                'effective_to' => now(),
                'active_slot' => null,
            ]);
            DB::table('client_distributor_assignments')->insert([
                'id' => $id,
                'client_id' => $current->clientId,
                'distributor_id' => $destination->id,
                'branch_id_snapshot' => $destination->branchId,
                'effective_from' => now(),
                'assignment_type' => 'AUTHORIZED_TRANSFER',
                'mobility_operation_id' => $operationId,
                'mobility_request_hash' => hash('sha256', $operationId),
                'reason' => $reason,
                'changed_by' => $actor->id,
                'active_slot' => true,
                'created_at' => now(),
            ]);
        }
        $this->assignments[$current->clientId] = new ClientAssignmentSnapshot(
            assignmentId: $id,
            clientId: $current->clientId,
            clientVersion: $current->clientVersion + 1,
            distributorId: $destination->id,
            branchId: $destination->branchId,
            portfolioVersion: 1,
            totalDue: '0.0000',
            overdue: '0.0000',
        );
        $this->applied++;

        return $id;
    }
}
