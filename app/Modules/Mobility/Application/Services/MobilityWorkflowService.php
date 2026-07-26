<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Application\Services;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\CriticalAction;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Mobility\Application\Contracts\ClientAssignmentSnapshot;
use App\Modules\Mobility\Application\Contracts\ClientMobilityPort;
use App\Modules\Mobility\Application\Contracts\MobilityReauthenticationPort;
use App\Modules\Mobility\Application\Contracts\MobilityRecorder;
use App\Modules\Mobility\Application\Contracts\OrganizationMobilityPort;
use App\Modules\Mobility\Application\Security\MobilityAccessService;
use App\Modules\Mobility\Domain\Enums\AdministrativeReassignmentStatus;
use App\Modules\Mobility\Domain\Enums\BranchChangeStatus;
use App\Modules\Mobility\Domain\Enums\ClientTransferStatus;
use App\Modules\Mobility\Domain\Enums\CoordinatorReassignmentStatus;
use App\Modules\Mobility\Domain\Exceptions\MobilityException;
use App\Modules\Mobility\Domain\Services\TransferStateMachine;
use App\Modules\Mobility\Domain\ValueObjects\MobilityFolio;
use App\Modules\Mobility\Infrastructure\Persistence\Models\AdministrativeReassignment;
use App\Modules\Mobility\Infrastructure\Persistence\Models\AdministrativeReassignmentItem;
use App\Modules\Mobility\Infrastructure\Persistence\Models\BranchChangeClientItem;
use App\Modules\Mobility\Infrastructure\Persistence\Models\ClientTransfer;
use App\Modules\Mobility\Infrastructure\Persistence\Models\CoordinatorReassignmentBatch;
use App\Modules\Mobility\Infrastructure\Persistence\Models\CoordinatorReassignmentItem;
use App\Modules\Mobility\Infrastructure\Persistence\Models\DistributorBranchChange;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Casos de uso transaccionales M15. Cada método representa una transición explícita;
 * ningún consumidor puede escribir estados o asignaciones directamente.
 */
final readonly class MobilityWorkflowService
{
    public function __construct(
        private ClientMobilityPort $clients,
        private OrganizationMobilityPort $organization,
        private MobilityReauthenticationPort $reauthentication,
        private MobilityRecorder $recorder,
        private MobilityAccessService $access,
        private TransferStateMachine $transitions,
    ) {}

    /** @param array<string, mixed> $input */
    public function createTransfer(User $actor, array $input, string $idempotencyKey, string $correlationId): ClientTransfer
    {
        return DB::transaction(function () use ($actor, $input, $idempotencyKey, $correlationId): ClientTransfer {
            $hash = $this->hash($input);
            $replay = ClientTransfer::query()
                ->where('requested_by', $actor->id)->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()->first();
            if ($replay !== null) {
                $this->assertHash($replay->request_hash, $hash);
                $replay->setAttribute('_idempotency_replayed', true);

                return $replay;
            }

            $assignment = $this->clients->lockAssignment((string) $input['client_id']);
            if ($assignment->clientVersion !== (int) $input['client_version']
                || $assignment->portfolioVersion !== (int) $input['portfolio_version']) {
                throw MobilityException::versionConflict();
            }
            $this->access->assertOriginDistributor($actor, $assignment->distributorId);
            $recipient = $this->clients->distributor((string) $input['recipient_distributor_id']);
            if (! $recipient->active) {
                throw MobilityException::invalidRecipient();
            }
            if ($recipient->id === $assignment->distributorId) {
                throw MobilityException::sameRecipient();
            }
            if (! $assignment->hasZeroBalance()) {
                throw MobilityException::balanceNotZero();
            }
            if (ClientTransfer::query()->where('client_id', $assignment->clientId)->where('active_slot', true)->exists()) {
                throw MobilityException::activeMobility();
            }
            $origin = $this->clients->distributor($assignment->distributorId);
            if (! $origin->active || $origin->coordinatorId === null) {
                throw MobilityException::invalidRecipient();
            }
            if (AdministrativeReassignmentItem::query()
                ->where('client_id', $assignment->clientId)
                ->whereHas('reassignment', fn ($query) => $query->whereIn('status', [
                    AdministrativeReassignmentStatus::DRAFT->value,
                    AdministrativeReassignmentStatus::VALIDATED->value,
                ]))->exists()) {
                throw MobilityException::activeMobility();
            }

            $transfer = new ClientTransfer;
            $transfer->forceFill([
                'id' => (string) Str::uuid(),
                'transfer_number' => $this->folio('TR'),
                'client_id' => $assignment->clientId,
                'origin_distributor_id' => $assignment->distributorId,
                'recipient_distributor_id' => $recipient->id,
                'origin_branch_id' => $assignment->branchId,
                'origin_coordinator_id' => $origin->coordinatorId,
                'status' => ClientTransferStatus::REQUESTED,
                'total_due_snapshot' => $assignment->totalDue,
                'overdue_snapshot' => $assignment->overdue,
                'requested_by' => $actor->id,
                'requested_at' => now(),
                'reason' => $input['reason'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
                'client_version' => $assignment->clientVersion,
                'portfolio_version' => $assignment->portfolioVersion,
                'lock_version' => 1,
                'active_slot' => true,
            ])->save();
            $this->recordTransition($transfer, null, ClientTransferStatus::REQUESTED->value, $actor, 'CreateClientTransfer', $transfer->reason, $correlationId);
            $this->recorder->outbox('ClientTransferRequested', 'client_transfer', $transfer->id, $correlationId, null, [
                'transfer_number' => $transfer->transfer_number,
                'recipient_distributor_id' => $recipient->id,
            ]);

            return $transfer;
        }, 3);
    }

    public function preaccept(
        User $actor,
        string $id,
        bool $accept,
        int $expectedVersion,
        ?string $reason,
        string $idempotencyKey,
        string $correlationId,
    ): ClientTransfer {
        return DB::transaction(function () use ($actor, $id, $accept, $expectedVersion, $reason, $idempotencyKey, $correlationId): ClientTransfer {
            $action = $accept ? 'PreacceptClientTransfer' : 'RejectClientTransferPreacceptance';
            if ($replay = $this->replayedAction($actor, $action, $idempotencyKey, $this->hash(compact('id', 'accept', 'expectedVersion', 'reason')), ClientTransfer::class)) {
                return $replay;
            }
            $transfer = ClientTransfer::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->access->assertRecipient($actor, $transfer);
            $this->assertVersion($transfer->lock_version, $expectedVersion);
            $next = $accept ? ClientTransferStatus::PREACCEPTED : ClientTransferStatus::REJECTED_BY_RECIPIENT;
            $this->transitions->assert($transfer->status, $next);
            $this->assertTransferAssignment($transfer, $accept);
            $previous = $transfer->status->value;
            $transfer->forceFill([
                'status' => $next,
                'preaccepted_by' => $accept ? $actor->id : null,
                'preaccepted_at' => $accept ? now() : null,
                'reason' => $reason,
                'active_slot' => $next->isActive() ? true : null,
                'lock_version' => $transfer->lock_version + 1,
            ])->save();
            $event = $accept ? 'ClientTransferPreaccepted' : 'ClientTransferPreacceptanceRejected';
            $this->recordTransition($transfer, $previous, $next->value, $actor, $action, $reason, $correlationId);
            $this->recorder->outbox($event, 'client_transfer', $transfer->id, $correlationId, null, []);
            if ($accept) {
                $this->recorder->outbox('ClientExitAuthorizationRequested', 'client_transfer', $transfer->id, $correlationId, null, [
                    'origin_coordinator_id' => $transfer->origin_coordinator_id,
                ]);
            }
            $this->rememberAction($actor, $action, $idempotencyKey, $this->hash(compact('id', 'accept', 'expectedVersion', 'reason')), $transfer);

            return $transfer;
        }, 3);
    }

    public function decideOrigin(
        User $actor,
        string $id,
        bool $authorize,
        int $expectedVersion,
        ?string $reason,
        string $idempotencyKey,
        string $correlationId,
    ): ClientTransfer {
        return DB::transaction(function () use ($actor, $id, $authorize, $expectedVersion, $reason, $idempotencyKey, $correlationId): ClientTransfer {
            $action = $authorize ? 'AuthorizeClientExit' : 'RejectClientExit';
            $hash = $this->hash(compact('id', 'authorize', 'expectedVersion', 'reason'));
            if ($replay = $this->replayedAction($actor, $action, $idempotencyKey, $hash, ClientTransfer::class)) {
                return $replay;
            }
            $transfer = ClientTransfer::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->access->assertOriginCoordinator($actor, $transfer);
            $this->assertVersion($transfer->lock_version, $expectedVersion);
            $next = $authorize ? ClientTransferStatus::ORIGIN_EXIT_AUTHORIZED : ClientTransferStatus::ORIGIN_EXIT_REJECTED;
            $this->transitions->assert($transfer->status, $next);
            $this->assertTransferAssignment($transfer, true);
            $previous = $transfer->status->value;
            $transfer->forceFill([
                'status' => $next,
                'origin_decided_by' => $actor->id,
                'origin_decided_at' => now(),
                'reason' => $reason,
                'active_slot' => $next->isActive() ? true : null,
                'lock_version' => $transfer->lock_version + 1,
            ])->save();
            $event = $authorize ? 'ClientExitAuthorized' : 'ClientExitRejected';
            $this->recordTransition($transfer, $previous, $next->value, $actor, $action, $reason, $correlationId);
            $this->recorder->outbox($event, 'client_transfer', $transfer->id, $correlationId, null, []);
            $this->rememberAction($actor, $action, $idempotencyKey, $hash, $transfer);

            return $transfer;
        }, 3);
    }

    public function finalizeTransfer(
        User $actor,
        string $id,
        int $expectedVersion,
        string $idempotencyKey,
        string $correlationId,
    ): ClientTransfer {
        return DB::transaction(function () use ($actor, $id, $expectedVersion, $idempotencyKey, $correlationId): ClientTransfer {
            $action = 'FinalizeClientTransfer';
            $hash = $this->hash(compact('id', 'expectedVersion'));
            if ($replay = $this->replayedAction($actor, $action, $idempotencyKey, $hash, ClientTransfer::class)) {
                return $replay;
            }
            $transfer = ClientTransfer::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->access->assertRecipient($actor, $transfer);
            $this->assertVersion($transfer->lock_version, $expectedVersion);
            $this->transitions->assert($transfer->status, ClientTransferStatus::COMPLETED);
            $current = $this->assertTransferAssignment($transfer, true);
            $destination = $this->clients->distributor($transfer->recipient_distributor_id);
            if (! $destination->active) {
                throw MobilityException::invalidRecipient();
            }
            $this->clients->applyAssignment($current, $destination, $transfer->id, $transfer->reason ?? 'CLIENT_TRANSFER', $actor);
            $previous = $transfer->status->value;
            $transfer->forceFill([
                'status' => ClientTransferStatus::COMPLETED,
                'final_accepted_by' => $actor->id,
                'final_accepted_at' => now(),
                'completed_at' => now(),
                'active_slot' => null,
                'lock_version' => $transfer->lock_version + 1,
            ])->save();
            $this->recordTransition($transfer, $previous, ClientTransferStatus::COMPLETED->value, $actor, $action, $transfer->reason, $correlationId);
            $this->recorder->outbox('ClientTransferFinalAccepted', 'client_transfer', $transfer->id, $correlationId, null, []);
            $this->recorder->outbox('ClientTransferCompleted', 'client_transfer', $transfer->id, $correlationId, null, [
                'client_id' => $transfer->client_id,
                'origin_distributor_id' => $transfer->origin_distributor_id,
                'recipient_distributor_id' => $transfer->recipient_distributor_id,
                'existing_client' => true,
                'next_voucher_type' => 'DIGITAL',
            ]);
            $this->rememberAction($actor, $action, $idempotencyKey, $hash, $transfer);

            return $transfer;
        }, 3);
    }

    public function cancelTransfer(): never
    {
        throw MobilityException::cancellationUndefined();
    }

    /** @param list<array{client_id:string,destination_distributor_id:string,client_version:int,portfolio_version:int}> $items */
    public function createAdministrativeReassignment(
        User $actor,
        array $items,
        string $reason,
        string $idempotencyKey,
        string $correlationId,
    ): AdministrativeReassignment {
        return DB::transaction(function () use ($actor, $items, $reason, $idempotencyKey, $correlationId): AdministrativeReassignment {
            $hash = $this->hash(['items' => $items, 'reason' => $reason]);
            $existing = AdministrativeReassignment::query()
                ->where('executed_by', $actor->id)->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()->first();
            if ($existing !== null) {
                $this->assertHash($existing->request_hash, $hash);
                $existing->setAttribute('_idempotency_replayed', true);

                return $existing->load('items');
            }
            $branchId = $actor->role_code === RoleCode::SUCURSAL_MANAGER->value ? $actor->branch_id : null;
            $this->access->assertManager(
                $actor, $branchId,
                PermissionCode::MOBILITY_REASSIGN_BRANCH,
                PermissionCode::MOBILITY_REASSIGN_GLOBAL,
            );
            if ($items === []) {
                throw MobilityException::invalidItem(['items' => ['Debe seleccionar al menos un cliente.']]);
            }
            $snapshots = $this->clients->lockAssignmentsForClients(array_column($items, 'client_id'));
            $byClient = collect($snapshots)->keyBy('clientId');
            $batch = new AdministrativeReassignment;
            $batch->forceFill([
                'id' => (string) Str::uuid(),
                'reassignment_number' => $this->folio('RA'),
                'status' => AdministrativeReassignmentStatus::DRAFT,
                'scope_branch_id' => $branchId,
                'reason' => trim($reason),
                'executed_by' => $actor->id,
                'executed_role' => $actor->role_code,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
                'lock_version' => 1,
            ])->save();
            foreach ($items as $item) {
                /** @var ClientAssignmentSnapshot|null $current */
                $current = $byClient->get($item['client_id']);
                if ($current === null
                    || $current->clientVersion !== $item['client_version']
                    || $current->portfolioVersion !== $item['portfolio_version']
                    || ! $current->hasZeroBalance()) {
                    throw MobilityException::invalidItem(['items' => ["El cliente {$item['client_id']} cambió o no tiene saldo cero."]]);
                }
                $destination = $this->clients->distributor($item['destination_distributor_id']);
                $this->assertManagerClientScope($actor, $current, $destination->branchId);
                if (! $destination->active || $destination->id === $current->distributorId) {
                    throw MobilityException::invalidItem();
                }
                if (ClientTransfer::query()->where('client_id', $current->clientId)->where('active_slot', true)->exists()) {
                    throw MobilityException::activeMobility();
                }
                $row = new AdministrativeReassignmentItem;
                $row->forceFill([
                    'administrative_reassignment_id' => $batch->id,
                    'client_id' => $current->clientId,
                    'origin_distributor_id' => $current->distributorId,
                    'destination_distributor_id' => $destination->id,
                    'origin_assignment_id' => $current->assignmentId,
                    'total_due_snapshot' => $current->totalDue,
                    'overdue_snapshot' => $current->overdue,
                    'client_version' => $current->clientVersion,
                    'portfolio_version' => $current->portfolioVersion,
                    'status' => 'PENDING',
                ])->save();
            }
            $this->recordTransition($batch, null, AdministrativeReassignmentStatus::DRAFT->value, $actor, 'CreateAdministrativeReassignment', $reason, $correlationId);
            $this->recorder->outbox('AdministrativeReassignmentCreated', 'administrative_reassignment', $batch->id, $correlationId, null, [
                'item_count' => count($items),
            ]);

            return $batch->load('items');
        }, 3);
    }

    public function validateAdministrativeReassignment(
        User $actor,
        string $id,
        int $expectedVersion,
        string $idempotencyKey,
        string $correlationId,
    ): AdministrativeReassignment {
        return DB::transaction(function () use ($actor, $id, $expectedVersion, $idempotencyKey, $correlationId): AdministrativeReassignment {
            $action = 'ValidateAdministrativeReassignment';
            $hash = $this->hash(compact('id', 'expectedVersion'));
            if ($replay = $this->replayedAction($actor, $action, $idempotencyKey, $hash, AdministrativeReassignment::class)) {
                return $replay->load('items');
            }
            $batch = AdministrativeReassignment::query()->with('items')->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertAdministrativeAuthority($actor, $batch);
            $this->assertVersion($batch->lock_version, $expectedVersion);
            if ($batch->status !== AdministrativeReassignmentStatus::DRAFT
                && $batch->status !== AdministrativeReassignmentStatus::REJECTED_BY_VALIDATION) {
                throw MobilityException::invalidState();
            }
            foreach ($batch->items->sortBy('client_id') as $item) {
                $current = $this->clients->lockAssignment($item->client_id);
                if ($current->assignmentId !== $item->origin_assignment_id
                    || ! $current->hasZeroBalance()
                    || ClientTransfer::query()->where('client_id', $item->client_id)->where('active_slot', true)->exists()) {
                    $batch->forceFill([
                        'status' => AdministrativeReassignmentStatus::REJECTED_BY_VALIDATION,
                        'validated_at' => now(),
                        'lock_version' => $batch->lock_version + 1,
                    ])->save();
                    $item->forceFill(['status' => 'INVALID', 'error_code' => 'REASSIGNMENT_ITEM_INVALID'])->save();
                    throw MobilityException::invalidItem(['items' => [$item->client_id]]);
                }
            }
            $previous = $batch->status->value;
            $batch->forceFill([
                'status' => AdministrativeReassignmentStatus::VALIDATED,
                'validated_at' => now(),
                'lock_version' => $batch->lock_version + 1,
            ])->save();
            $batch->items()->update(['status' => 'VALIDATED', 'error_code' => null, 'updated_at' => now()]);
            $this->recordTransition($batch, $previous, AdministrativeReassignmentStatus::VALIDATED->value, $actor, $action, null, $correlationId);
            $this->recorder->outbox('AdministrativeReassignmentValidated', 'administrative_reassignment', $batch->id, $correlationId, null, []);
            $this->rememberAction($actor, $action, $idempotencyKey, $hash, $batch);

            return $batch->load('items');
        }, 3);
    }

    public function completeAdministrativeReassignment(
        User $actor,
        string $id,
        int $expectedVersion,
        string $reauthenticationToken,
        string $idempotencyKey,
        string $correlationId,
    ): AdministrativeReassignment {
        return DB::transaction(function () use ($actor, $id, $expectedVersion, $reauthenticationToken, $idempotencyKey, $correlationId): AdministrativeReassignment {
            $action = 'CompleteAdministrativeReassignment';
            $hash = $this->hash(compact('id', 'expectedVersion'));
            if ($replay = $this->replayedAction($actor, $action, $idempotencyKey, $hash, AdministrativeReassignment::class)) {
                return $replay->load('items');
            }
            $batch = AdministrativeReassignment::query()->with('items')->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertAdministrativeAuthority($actor, $batch);
            $this->assertVersion($batch->lock_version, $expectedVersion);
            if ($batch->status !== AdministrativeReassignmentStatus::VALIDATED) {
                throw MobilityException::partialCompletion();
            }
            $authorizationId = $this->reauthentication->consume(
                $actor, $reauthenticationToken, CriticalAction::MOBILITY_ADMINISTRATIVE_REASSIGNMENT,
                AdministrativeReassignment::class, $batch->id,
            );
            foreach ($batch->items->sortBy('client_id') as $item) {
                $current = $this->clients->lockAssignment($item->client_id);
                if ($current->assignmentId !== $item->origin_assignment_id || ! $current->hasZeroBalance()) {
                    throw MobilityException::invalidItem(['items' => [$item->client_id]]);
                }
                $destination = $this->clients->distributor($item->destination_distributor_id);
                $destinationAssignmentId = $this->clients->applyAssignment(
                    $current, $destination, $item->id, $batch->reason, $actor,
                );
                $item->forceFill([
                    'destination_assignment_id' => $destinationAssignmentId,
                    'status' => 'COMPLETED',
                    'completed_at' => now(),
                ])->save();
                $this->recorder->outbox('ClientAdministrativelyReassigned', 'administrative_reassignment', $batch->id, $correlationId, null, [
                    'client_id' => $item->client_id,
                    'origin_distributor_id' => $item->origin_distributor_id,
                    'destination_distributor_id' => $item->destination_distributor_id,
                ]);
            }
            $batch->forceFill([
                'status' => AdministrativeReassignmentStatus::COMPLETED,
                'reauthentication_id' => $authorizationId,
                'completed_at' => now(),
                'lock_version' => $batch->lock_version + 1,
            ])->save();
            $this->recordTransition($batch, AdministrativeReassignmentStatus::VALIDATED->value, AdministrativeReassignmentStatus::COMPLETED->value, $actor, $action, $batch->reason, $correlationId);
            $this->recorder->outbox('AdministrativeReassignmentCompleted', 'administrative_reassignment', $batch->id, $correlationId, null, [
                'item_count' => $batch->items->count(),
            ]);
            $this->rememberAction($actor, $action, $idempotencyKey, $hash, $batch);

            return $batch->load('items');
        }, 3);
    }

    /** @param array<string, mixed> $input */
    public function createBranchChange(User $actor, array $input, string $idempotencyKey, string $correlationId): DistributorBranchChange
    {
        return DB::transaction(function () use ($actor, $input, $idempotencyKey, $correlationId): DistributorBranchChange {
            $distributor = $this->clients->distributor((string) $input['distributor_id']);
            $this->access->assertManager(
                $actor, $distributor->branchId,
                PermissionCode::MOBILITY_BRANCH_CHANGE_BRANCH,
                PermissionCode::MOBILITY_BRANCH_CHANGE_GLOBAL,
            );
            $this->organization->assertDistributorBranch($distributor->id, $distributor->branchId);
            $this->organization->assertBranchExists((int) $input['destination_branch_id']);
            if ($distributor->branchId === (int) $input['destination_branch_id']) {
                throw MobilityException::originChanged();
            }
            $hash = $this->hash($input);
            $existing = DistributorBranchChange::query()
                ->where('requested_by', $actor->id)->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()->first();
            if ($existing !== null) {
                $this->assertHash($existing->request_hash, $hash);
                $existing->setAttribute('_idempotency_replayed', true);

                return $existing;
            }
            if (DistributorBranchChange::query()->where('distributor_id', $distributor->id)->where('active_slot', true)->exists()) {
                throw MobilityException::branchChangeActive();
            }
            $change = new DistributorBranchChange;
            $change->forceFill([
                'id' => (string) Str::uuid(),
                'change_number' => $this->folio('SC'),
                'distributor_id' => $distributor->id,
                'origin_branch_id' => $distributor->branchId,
                'destination_branch_id' => (int) $input['destination_branch_id'],
                'status' => BranchChangeStatus::REQUESTED,
                'reason' => trim((string) $input['reason']),
                'requested_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
                'lock_version' => 1,
                'active_slot' => true,
            ])->save();
            $this->recordTransition($change, null, BranchChangeStatus::REQUESTED->value, $actor, 'CreateDistributorBranchChange', $change->reason, $correlationId);
            $this->recorder->outbox('DistributorBranchChangeRequested', 'distributor_branch_change', $change->id, $correlationId, null, []);

            return $change;
        }, 3);
    }

    public function authorizeBranchChange(User $actor, string $id, int $expectedVersion, string $token, string $correlationId): DistributorBranchChange
    {
        return DB::transaction(function () use ($actor, $id, $expectedVersion, $token, $correlationId): DistributorBranchChange {
            $change = DistributorBranchChange::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertBranchAuthority($actor, $change);
            $this->assertVersion($change->lock_version, $expectedVersion);
            if ($change->status !== BranchChangeStatus::REQUESTED) {
                throw MobilityException::invalidState();
            }
            $this->organization->assertDistributorBranch($change->distributor_id, $change->origin_branch_id);
            $authorizationId = $this->reauthentication->consume(
                $actor, $token, CriticalAction::MOBILITY_BRANCH_CHANGE, DistributorBranchChange::class, $change->id,
            );
            $clients = $this->clients->currentAssignmentsForDistributor($change->distributor_id);
            foreach ($clients as $assignment) {
                $item = new BranchChangeClientItem;
                $item->forceFill([
                    'branch_change_id' => $change->id,
                    'client_id' => $assignment->clientId,
                    'origin_distributor_id' => $change->distributor_id,
                    'status' => 'PENDING',
                ])->save();
            }
            $next = $clients === []
                ? BranchChangeStatus::DESTINATION_COORDINATOR_PENDING
                : BranchChangeStatus::CLIENT_REASSIGNMENT_PENDING;
            $change->forceFill([
                'status' => $next,
                'authorized_by' => $actor->id,
                'authorized_role' => $actor->role_code,
                'reauthentication_id' => $authorizationId,
                'authorized_at' => now(),
                'lock_version' => $change->lock_version + 1,
            ])->save();
            $this->recordTransition($change, BranchChangeStatus::REQUESTED->value, $next->value, $actor, 'AuthorizeDistributorBranchChange', $change->reason, $correlationId);
            $this->recorder->outbox('DistributorBranchChangeAuthorized', 'distributor_branch_change', $change->id, $correlationId, null, []);
            if ($clients !== []) {
                $this->recorder->outbox('BranchChangeClientsPending', 'distributor_branch_change', $change->id, $correlationId, null, [
                    'pending_count' => count($clients),
                ]);
            }

            return $change->load('clientItems');
        }, 3);
    }

    /** @param list<array{client_id:string,destination_distributor_id:string}> $assignments */
    public function assignBranchClientDestinations(
        User $actor,
        string $id,
        array $assignments,
        int $expectedVersion,
    ): DistributorBranchChange {
        return DB::transaction(function () use ($actor, $id, $assignments, $expectedVersion): DistributorBranchChange {
            $change = DistributorBranchChange::query()->with('clientItems')->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertBranchAuthority($actor, $change);
            $this->assertVersion($change->lock_version, $expectedVersion);
            if ($change->status !== BranchChangeStatus::CLIENT_REASSIGNMENT_PENDING) {
                throw MobilityException::invalidState();
            }
            $provided = collect($assignments)->keyBy('client_id');
            if ($provided->count() !== $change->clientItems->count()) {
                throw MobilityException::partialCompletion();
            }
            foreach ($change->clientItems as $item) {
                $target = $provided->get($item->client_id);
                if (! is_array($target)) {
                    throw MobilityException::partialCompletion();
                }
                $item->forceFill([
                    'destination_distributor_id' => $target['destination_distributor_id'],
                    'status' => 'DESTINATION_ASSIGNED',
                ])->save();
            }
            $change->forceFill(['lock_version' => $change->lock_version + 1])->save();

            return $change->load('clientItems');
        }, 3);
    }

    public function assignDestinationCoordinator(
        User $actor,
        string $id,
        int $coordinatorId,
        int $expectedVersion,
        string $correlationId,
    ): DistributorBranchChange {
        return DB::transaction(function () use ($actor, $id, $coordinatorId, $expectedVersion, $correlationId): DistributorBranchChange {
            $change = DistributorBranchChange::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertBranchAuthority($actor, $change);
            $this->assertVersion($change->lock_version, $expectedVersion);
            $this->organization->assertCoordinatorValid($coordinatorId, $change->destination_branch_id);
            $remaining = $this->clients->currentAssignmentsForDistributor($change->distributor_id);
            $next = $remaining === [] ? BranchChangeStatus::READY_TO_COMPLETE : BranchChangeStatus::CLIENT_REASSIGNMENT_PENDING;
            $previous = $change->status->value;
            $change->forceFill([
                'destination_coordinator_id' => $coordinatorId,
                'status' => $next,
                'lock_version' => $change->lock_version + 1,
            ])->save();
            $this->recordTransition($change, $previous, $next->value, $actor, 'AssignDestinationCoordinator', null, $correlationId);
            $this->recorder->outbox('BranchChangeDestinationCoordinatorAssigned', 'distributor_branch_change', $change->id, $correlationId, null, [
                'destination_coordinator_id' => $coordinatorId,
            ]);

            return $change;
        }, 3);
    }

    public function completeBranchChange(User $actor, string $id, int $expectedVersion, string $token, string $correlationId): DistributorBranchChange
    {
        return DB::transaction(function () use ($actor, $id, $expectedVersion, $token, $correlationId): DistributorBranchChange {
            $change = DistributorBranchChange::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertBranchAuthority($actor, $change);
            $this->assertVersion($change->lock_version, $expectedVersion);
            if ($change->status !== BranchChangeStatus::READY_TO_COMPLETE) {
                throw MobilityException::invalidState();
            }
            if ($this->clients->currentAssignmentsForDistributor($change->distributor_id) !== []) {
                throw MobilityException::clientsPending();
            }
            if ($change->destination_coordinator_id === null) {
                throw MobilityException::coordinatorRequired();
            }
            $this->organization->assertDistributorBranch($change->distributor_id, $change->origin_branch_id);
            $this->organization->assertCoordinatorValid($change->destination_coordinator_id, $change->destination_branch_id);
            $this->reauthentication->consume(
                $actor, $token, CriticalAction::MOBILITY_BRANCH_CHANGE, DistributorBranchChange::class, $change->id,
            );
            $this->organization->moveDistributorBranch(
                $change->distributor_id,
                $change->origin_branch_id,
                $change->destination_branch_id,
                $change->destination_coordinator_id,
                $change->id,
                $change->reason,
                $actor,
            );
            $change->forceFill([
                'status' => BranchChangeStatus::COMPLETED,
                'completed_at' => now(),
                'active_slot' => null,
                'lock_version' => $change->lock_version + 1,
            ])->save();
            $this->recordTransition($change, BranchChangeStatus::READY_TO_COMPLETE->value, BranchChangeStatus::COMPLETED->value, $actor, 'CompleteDistributorBranchChange', $change->reason, $correlationId);
            $this->recorder->outbox('DistributorBranchChanged', 'distributor_branch_change', $change->id, $correlationId, null, [
                'distributor_id' => $change->distributor_id,
                'origin_branch_id' => $change->origin_branch_id,
                'destination_branch_id' => $change->destination_branch_id,
            ]);

            return $change;
        }, 3);
    }

    public function cancelBranchChange(): never
    {
        throw MobilityException::cancellationUndefined();
    }

    public function createCoordinatorBatch(
        User $actor,
        int $outgoingCoordinatorId,
        int $branchId,
        string $reason,
        string $idempotencyKey,
        string $token,
        string $correlationId,
    ): CoordinatorReassignmentBatch {
        return DB::transaction(function () use ($actor, $outgoingCoordinatorId, $branchId, $reason, $idempotencyKey, $token, $correlationId): CoordinatorReassignmentBatch {
            $this->access->assertManager(
                $actor, $branchId,
                PermissionCode::MOBILITY_COORDINATOR_REASSIGN_BRANCH,
                PermissionCode::MOBILITY_COORDINATOR_REASSIGN_GLOBAL,
            );
            $hash = $this->hash(compact('outgoingCoordinatorId', 'branchId', 'reason'));
            $existing = CoordinatorReassignmentBatch::query()
                ->where('registered_by', $actor->id)->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()->first();
            if ($existing !== null) {
                $this->assertHash($existing->request_hash, $hash);
                $existing->setAttribute('_idempotency_replayed', true);

                return $existing->load('items');
            }
            if (CoordinatorReassignmentBatch::query()->where('outgoing_coordinator_id', $outgoingCoordinatorId)->where('active_slot', true)->exists()) {
                throw MobilityException::coordinatorBatchActive();
            }
            $distributorIds = $this->organization->lockDistributorIdsForCoordinator($outgoingCoordinatorId);
            $batchId = (string) Str::uuid();
            $authorizationId = $this->reauthentication->consume(
                $actor, $token, CriticalAction::MOBILITY_COORDINATOR_REASSIGNMENT,
                CoordinatorReassignmentBatch::class, $batchId,
            );
            $batch = new CoordinatorReassignmentBatch;
            $batch->forceFill([
                'id' => $batchId,
                'batch_number' => $this->folio('CO'),
                'outgoing_coordinator_id' => $outgoingCoordinatorId,
                'branch_id' => $branchId,
                'status' => $distributorIds === [] ? CoordinatorReassignmentStatus::READY_TO_COMPLETE : CoordinatorReassignmentStatus::ASSIGNMENT_PENDING,
                'reason' => trim($reason),
                'registered_by' => $actor->id,
                'reauthentication_id' => $authorizationId,
                'snapshot_count' => count($distributorIds),
                'current_count' => count($distributorIds),
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
                'lock_version' => 1,
                'active_slot' => true,
            ])->save();
            foreach ($distributorIds as $distributorId) {
                $item = new CoordinatorReassignmentItem;
                $item->forceFill([
                    'batch_id' => $batch->id,
                    'distributor_id' => $distributorId,
                    'origin_coordinator_id' => $outgoingCoordinatorId,
                    'status' => 'PENDING',
                ])->save();
            }
            $this->recordTransition($batch, null, $batch->status->value, $actor, 'CreateCoordinatorDepartureBatch', $reason, $correlationId);
            $this->recorder->outbox('CoordinatorDepartureRegistered', 'coordinator_reassignment', $batch->id, $correlationId, null, [
                'outgoing_coordinator_id' => $outgoingCoordinatorId,
                'snapshot_count' => count($distributorIds),
            ]);

            return $batch->load('items');
        }, 3);
    }

    /** @param list<array{distributor_id:string,destination_coordinator_id:int}> $assignments */
    public function assignDistributorToCoordinator(
        User $actor,
        string $id,
        array $assignments,
        int $expectedVersion,
    ): CoordinatorReassignmentBatch {
        return DB::transaction(function () use ($actor, $id, $assignments, $expectedVersion): CoordinatorReassignmentBatch {
            $batch = CoordinatorReassignmentBatch::query()->with('items')->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertCoordinatorBatchAuthority($actor, $batch);
            $this->assertVersion($batch->lock_version, $expectedVersion);
            $provided = collect($assignments)->keyBy('distributor_id');
            foreach ($batch->items as $item) {
                $destination = $provided->get($item->distributor_id);
                if (! is_array($destination)) {
                    continue;
                }
                $coordinatorId = (int) $destination['destination_coordinator_id'];
                if ($coordinatorId === $batch->outgoing_coordinator_id) {
                    throw MobilityException::invalidCoordinator();
                }
                $this->organization->assertCoordinatorValid($coordinatorId, $batch->branch_id);
                $item->forceFill([
                    'destination_coordinator_id' => $coordinatorId,
                    'status' => 'ASSIGNED',
                ])->save();
            }
            $covered = $batch->items()->whereNotNull('destination_coordinator_id')->count();
            $batch->forceFill([
                'status' => $covered === $batch->snapshot_count
                    ? CoordinatorReassignmentStatus::READY_TO_COMPLETE
                    : CoordinatorReassignmentStatus::ASSIGNMENT_PENDING,
                'lock_version' => $batch->lock_version + 1,
            ])->save();

            return $batch->load('items');
        }, 3);
    }

    public function validateCoordinatorBatch(User $actor, string $id, int $expectedVersion, string $correlationId): CoordinatorReassignmentBatch
    {
        return DB::transaction(function () use ($actor, $id, $expectedVersion, $correlationId): CoordinatorReassignmentBatch {
            $batch = CoordinatorReassignmentBatch::query()->with('items')->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertCoordinatorBatchAuthority($actor, $batch);
            $this->assertVersion($batch->lock_version, $expectedVersion);
            $current = $this->organization->lockDistributorIdsForCoordinator($batch->outgoing_coordinator_id);
            $covered = $batch->items->whereNotNull('destination_coordinator_id')->pluck('distributor_id')->sort()->values()->all();
            sort($current);
            if ($current !== $covered) {
                $batch->forceFill([
                    'status' => CoordinatorReassignmentStatus::ASSIGNMENT_PENDING,
                    'current_count' => count($current),
                    'lock_version' => $batch->lock_version + 1,
                ])->save();
                $this->recorder->outbox('CoordinatorCoverageIncomplete', 'coordinator_reassignment', $batch->id, $correlationId, null, [
                    'snapshot_count' => $batch->snapshot_count,
                    'current_count' => count($current),
                ]);
                throw MobilityException::coverageIncomplete();
            }
            foreach ($batch->items as $item) {
                $this->organization->assertCoordinatorValid((int) $item->destination_coordinator_id, $batch->branch_id);
            }
            $previous = $batch->status->value;
            $batch->forceFill([
                'status' => CoordinatorReassignmentStatus::READY_TO_COMPLETE,
                'current_count' => count($current),
                'lock_version' => $batch->lock_version + 1,
            ])->save();
            $this->recordTransition($batch, $previous, CoordinatorReassignmentStatus::READY_TO_COMPLETE->value, $actor, 'ValidateCoordinatorDepartureBatch', null, $correlationId);

            return $batch->load('items');
        }, 3);
    }

    public function completeCoordinatorBatch(User $actor, string $id, int $expectedVersion, string $token, string $correlationId): CoordinatorReassignmentBatch
    {
        return DB::transaction(function () use ($actor, $id, $expectedVersion, $token, $correlationId): CoordinatorReassignmentBatch {
            $batch = CoordinatorReassignmentBatch::query()->with('items')->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertCoordinatorBatchAuthority($actor, $batch);
            $this->assertVersion($batch->lock_version, $expectedVersion);
            if ($batch->status !== CoordinatorReassignmentStatus::READY_TO_COMPLETE) {
                throw MobilityException::coverageIncomplete();
            }
            $current = $this->organization->lockDistributorIdsForCoordinator($batch->outgoing_coordinator_id);
            $destinations = $batch->items->pluck('destination_coordinator_id', 'distributor_id')
                ->map(static fn ($id): int => (int) $id)->all();
            $currentSorted = $current;
            sort($currentSorted);
            $covered = array_keys($destinations);
            sort($covered);
            if ($currentSorted !== $covered) {
                throw MobilityException::coverageIncomplete();
            }
            $this->reauthentication->consume(
                $actor, $token, CriticalAction::MOBILITY_COORDINATOR_REASSIGNMENT,
                CoordinatorReassignmentBatch::class, $batch->id,
            );
            $this->organization->reassignCoordinatorCoverage(
                $batch->outgoing_coordinator_id,
                $batch->branch_id,
                $destinations,
                $batch->id,
                $batch->reason,
                $actor,
            );
            foreach ($batch->items as $item) {
                $item->forceFill(['status' => 'COMPLETED', 'completed_at' => now()])->save();
                $this->recorder->outbox('DistributorCoordinatorReassigned', 'coordinator_reassignment', $batch->id, $correlationId, null, [
                    'distributor_id' => $item->distributor_id,
                    'destination_coordinator_id' => $item->destination_coordinator_id,
                ]);
            }
            $batch->forceFill([
                'status' => CoordinatorReassignmentStatus::COMPLETED,
                'completed_at' => now(),
                'active_slot' => null,
                'lock_version' => $batch->lock_version + 1,
            ])->save();
            $this->recordTransition($batch, CoordinatorReassignmentStatus::READY_TO_COMPLETE->value, CoordinatorReassignmentStatus::COMPLETED->value, $actor, 'CompleteCoordinatorDepartureBatch', $batch->reason, $correlationId);
            $this->recorder->outbox('CoordinatorReassignmentCompleted', 'coordinator_reassignment', $batch->id, $correlationId, null, [
                'outgoing_coordinator_id' => $batch->outgoing_coordinator_id,
                'count' => count($destinations),
            ]);

            return $batch->load('items');
        }, 3);
    }

    private function assertTransferAssignment(ClientTransfer $transfer, bool $balance): ClientAssignmentSnapshot
    {
        $current = $this->clients->lockAssignment($transfer->client_id);
        if ($current->distributorId !== $transfer->origin_distributor_id) {
            throw MobilityException::assignmentConflict();
        }
        if ($balance && ! $current->hasZeroBalance()) {
            throw MobilityException::balanceNotZero();
        }

        return $current;
    }

    private function assertManagerClientScope(User $actor, ClientAssignmentSnapshot $current, int $destinationBranchId): void
    {
        if ($actor->role_code === RoleCode::SUCURSAL_MANAGER->value
            && ($actor->branch_id !== $current->branchId || $actor->branch_id !== $destinationBranchId)) {
            throw MobilityException::scopeDenied();
        }
    }

    private function assertAdministrativeAuthority(User $actor, AdministrativeReassignment $batch): void
    {
        $this->access->assertManager(
            $actor,
            $batch->scope_branch_id === null ? null : (int) $batch->scope_branch_id,
            PermissionCode::MOBILITY_REASSIGN_BRANCH,
            PermissionCode::MOBILITY_REASSIGN_GLOBAL,
        );
    }

    private function assertBranchAuthority(User $actor, DistributorBranchChange $change): void
    {
        $this->access->assertManager(
            $actor,
            (int) $change->origin_branch_id,
            PermissionCode::MOBILITY_BRANCH_CHANGE_BRANCH,
            PermissionCode::MOBILITY_BRANCH_CHANGE_GLOBAL,
        );
    }

    private function assertCoordinatorBatchAuthority(User $actor, CoordinatorReassignmentBatch $batch): void
    {
        $this->access->assertManager(
            $actor,
            (int) $batch->branch_id,
            PermissionCode::MOBILITY_COORDINATOR_REASSIGN_BRANCH,
            PermissionCode::MOBILITY_COORDINATOR_REASSIGN_GLOBAL,
        );
    }

    private function assertVersion(int $current, int $expected): void
    {
        if ($current !== $expected) {
            throw MobilityException::versionConflict();
        }
    }

    /** @param array<string, mixed> $payload */
    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function assertHash(string $stored, string $actual): void
    {
        if (! hash_equals($stored, $actual)) {
            throw MobilityException::idempotencyConflict();
        }
    }

    private function folio(string $kind): string
    {
        return (string) new MobilityFolio('MV15-'.$kind.'-'.strtoupper(Str::random(12)));
    }

    private function recordTransition(
        ClientTransfer|AdministrativeReassignment|DistributorBranchChange|CoordinatorReassignmentBatch $aggregate,
        ?string $previous,
        string $next,
        User $actor,
        string $useCase,
        ?string $reason,
        string $correlationId,
    ): void {
        $type = match (true) {
            $aggregate instanceof ClientTransfer => 'client_transfer',
            $aggregate instanceof AdministrativeReassignment => 'administrative_reassignment',
            $aggregate instanceof DistributorBranchChange => 'distributor_branch_change',
            default => 'coordinator_reassignment',
        };
        $branch = match (true) {
            $aggregate instanceof ClientTransfer => $aggregate->origin_branch_id,
            $aggregate instanceof AdministrativeReassignment => $aggregate->scope_branch_id,
            $aggregate instanceof DistributorBranchChange => $aggregate->origin_branch_id,
            default => $aggregate->branch_id,
        };
        $this->recorder->history($type, $aggregate->id, $previous, $next, $actor, $branch, $useCase, $reason, $correlationId);
        $this->recorder->audit($useCase, $type, $aggregate->id, $actor, $branch, 'SUCCESS', $reason, ['status' => $previous], ['status' => $next]);
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $model
     * @return T|null
     */
    private function replayedAction(User $actor, string $action, string $key, string $hash, string $model): ?object
    {
        $record = DB::table('mobility_action_idempotency')
            ->where('actor_user_id', $actor->id)->where('action', $action)->where('idempotency_key', $key)
            ->lockForUpdate()->first();
        if ($record === null) {
            return null;
        }
        $this->assertHash((string) $record->request_hash, $hash);
        $aggregate = $model::query()->whereKey($record->aggregate_id)->firstOrFail();
        $aggregate->setAttribute('_idempotency_replayed', true);

        return $aggregate;
    }

    private function rememberAction(User $actor, string $action, string $key, string $hash, object $aggregate): void
    {
        try {
            DB::table('mobility_action_idempotency')->insert([
                'actor_user_id' => $actor->id,
                'action' => $action,
                'idempotency_key' => $key,
                'request_hash' => $hash,
                'aggregate_type' => class_basename($aggregate),
                'aggregate_id' => $aggregate->id,
                'result_version' => $aggregate->lock_version,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            throw MobilityException::idempotencyConflict();
        }
    }
}
