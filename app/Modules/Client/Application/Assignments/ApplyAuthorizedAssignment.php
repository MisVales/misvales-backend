<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Assignments;

use App\Modules\Client\Application\Contracts\ApplyAuthorizedClientAssignment;
use App\Modules\Client\Application\Contracts\ApplyAuthorizedClientAssignmentCommand;
use App\Modules\Client\Application\Contracts\AuthorizedMobilityPort;
use App\Modules\Client\Application\Contracts\ClientAuditPort;
use App\Modules\Client\Application\Contracts\ClientOutboxPort;
use App\Modules\Client\Application\Contracts\DistributorProfilePort;
use App\Modules\Client\Application\Contracts\ValidateClientPortfolioForTransfer;
use App\Modules\Client\Application\Contracts\ValidateClientPortfolioForTransferQuery;
use App\Modules\Client\Domain\Assignments\AssignmentType;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Client\Persistence\Models\Client;
use App\Modules\Client\Persistence\Models\ClientDistributorAssignment;
use App\Modules\Client\Persistence\Models\ClientPortfolioSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Cierra y crea asignaciones sin volver a decidir el flujo propietario de M15. */
final readonly class ApplyAuthorizedAssignment implements ApplyAuthorizedClientAssignment
{
    public function __construct(
        private AuthorizedMobilityPort $mobility,
        private DistributorProfilePort $profiles,
        private ValidateClientPortfolioForTransfer $portfolio,
        private ClientAuditPort $audit,
        private ClientOutboxPort $outbox,
    ) {}

    public function handle(ApplyAuthorizedClientAssignmentCommand $command): void
    {
        $requestHash = $this->requestHash($command);

        DB::transaction(function () use ($command, $requestHash): void {
            $client = Client::query()->whereKey($command->clientId)->lockForUpdate()->first();
            if ($client === null) {
                throw ClientDomainException::notFoundOrOutOfScope();
            }
            $replayed = ClientDistributorAssignment::query()
                ->where('mobility_operation_id', $command->mobilityOperationId)
                ->first();
            if ($replayed !== null) {
                if (
                    $replayed->client_id !== $command->clientId
                    || $replayed->distributor_id !== $command->destinationDistributorId
                    || ! is_string($replayed->mobility_request_hash)
                    || ! hash_equals($replayed->mobility_request_hash, $requestHash)
                ) {
                    throw ClientDomainException::idempotencyConflict();
                }

                return;
            }
            if ($client->lock_version !== $command->expectedClientVersion) {
                throw ClientDomainException::versionConflict();
            }
            $assignment = ClientDistributorAssignment::query()
                ->where('client_id', $client->id)
                ->where('active_slot', true)
                ->lockForUpdate()
                ->firstOrFail();
            if ($assignment->distributor_id !== $command->sourceDistributorId) {
                throw ClientDomainException::notAssigned();
            }

            $this->mobility->assertAuthorized(
                $command->mobilityOperationId,
                $command->clientId,
                $command->sourceDistributorId,
                $command->destinationDistributorId,
            );
            $balance = $this->portfolio->handle(new ValidateClientPortfolioForTransferQuery(
                $command->clientId,
                $command->sourceDistributorId,
                $command->expectedPortfolioVersion,
            ));
            if (! $balance->allowed) {
                throw ClientDomainException::transferBalanceNotZero();
            }
            $destination = $this->profiles->activeById($command->destinationDistributorId);

            $assignment->forceFill([
                'effective_to' => $command->effectiveAt,
                'active_slot' => null,
            ])->save();

            $next = new ClientDistributorAssignment;
            $next->forceFill([
                'id' => (string) Str::uuid(),
                'client_id' => $client->id,
                'distributor_id' => $destination->distributorId,
                'branch_id_snapshot' => $destination->branchId,
                'effective_from' => $command->effectiveAt,
                'effective_to' => null,
                'assignment_type' => AssignmentType::AUTHORIZED_TRANSFER,
                'mobility_operation_id' => $command->mobilityOperationId,
                'mobility_request_hash' => $requestHash,
                'reason' => $command->reason,
                'changed_by' => $command->executor->userId,
                'active_slot' => true,
            ])->save();

            $setting = new ClientPortfolioSetting;
            $setting->forceFill([
                'id' => (string) Str::uuid(),
                'client_id' => $client->id,
                'distributor_id' => $destination->distributorId,
                'assignment_id' => $next->id,
                'tracking_enabled' => false,
                'lock_version' => 1,
                'updated_by' => $command->executor->userId,
            ])->save();

            $client->forceFill(['lock_version' => $client->lock_version + 1])->save();
            $this->audit->record(
                'CLIENT_DISTRIBUTOR_ASSIGNMENT_CHANGED',
                $client->id,
                $command->executor,
                $destination->distributorId,
                $command->mobilityOperationId,
                ['distributor_assignment'],
                'SUCCESS',
                $command->requestId,
                $command->reason,
            );
            $this->outbox->append('ClientDistributorAssignmentChanged', [
                'client_id' => $client->id,
                'source_distributor_id' => $command->sourceDistributorId,
                'destination_distributor_id' => $destination->distributorId,
                'effective_at' => $command->effectiveAt,
            ], 'client-assignment:'.$command->mobilityOperationId);
        }, 3);
    }

    private function requestHash(ApplyAuthorizedClientAssignmentCommand $command): string
    {
        foreach ([
            'mobility_operation_id' => $command->mobilityOperationId,
            'client_id' => $command->clientId,
            'source_distributor_id' => $command->sourceDistributorId,
            'destination_distributor_id' => $command->destinationDistributorId,
            'request_id' => $command->requestId,
        ] as $field => $identifier) {
            if (! Str::isUuid($identifier)) {
                throw ClientDomainException::dataIncomplete($field);
            }
        }

        return hash('sha256', json_encode([
            'client_id' => $command->clientId,
            'source_distributor_id' => $command->sourceDistributorId,
            'destination_distributor_id' => $command->destinationDistributorId,
            'effective_at' => CarbonImmutable::parse($command->effectiveAt)->utc()->toIso8601String(),
            'reason' => trim($command->reason),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
