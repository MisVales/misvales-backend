<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\Services;

use App\Models\User;
use App\Modules\ExcessBalance\Application\Contracts\ExcessEventPublisher;
use App\Modules\ExcessBalance\Application\DTOs\OperationContext;
use App\Modules\ExcessBalance\Domain\Enums\ExcessBalanceBucket;
use App\Modules\ExcessBalance\Domain\Enums\ExcessLedgerEntryType;
use App\Modules\ExcessBalance\Infrastructure\Persistence\Eloquent\Models\ExcessLedgerEntryModel;
use App\Modules\ExcessBalance\Infrastructure\Persistence\Eloquent\Models\ExcessStatusHistoryModel;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\ExcessBalanceModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ExcessRecorder
{
    public function __construct(private ExcessEventPublisher $events) {}

    /** @param array<string, mixed> $metadata */
    public function ledger(
        string $excessId,
        ExcessLedgerEntryType $type,
        string $amount,
        ?ExcessBalanceBucket $from,
        ?ExcessBalanceBucket $to,
        string $idempotencyKey,
        ?int $actorId,
        ?string $applicationId = null,
        ?string $refundId = null,
        array $metadata = [],
    ): void {
        ExcessLedgerEntryModel::query()->create([
            'excess_balance_id' => $excessId,
            'entry_type' => $type,
            'amount' => $amount,
            'balance_bucket_from' => $from,
            'balance_bucket_to' => $to,
            'excess_application_id' => $applicationId,
            'refund_request_id' => $refundId,
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => now('UTC'),
            'actor_id' => $actorId,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    /**
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     */
    public function history(
        ExcessBalanceModel $balance,
        ?string $previousStatus,
        string $newStatus,
        array $before,
        array $after,
        string $idempotencyKey,
        ?int $actorId,
        ?string $reason = null,
        ?string $refundId = null,
        ?string $applicationId = null,
    ): void {
        ExcessStatusHistoryModel::query()->create([
            'excess_balance_id' => $balance->id,
            'refund_request_id' => $refundId,
            'excess_application_id' => $applicationId,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'amounts_before' => $before,
            'amounts_after' => $after,
            'actor_id' => $actorId,
            'actor_type' => $actorId === null ? 'SYSTEM' : 'USER',
            'reason' => $reason,
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => now('UTC'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    public function audit(
        string $action,
        string $result,
        string $resourceType,
        string $resourceId,
        ?OperationContext $context,
        array $before = [],
        array $after = [],
        array $metadata = [],
        ?string $reason = null,
        ?int $systemBranchId = null,
    ): void {
        $actor = $context?->actor;
        DB::table('excess_audits')->insert([
            'id' => (string) Str::uuid(),
            'action' => $action,
            'result' => $result,
            'actor_id' => $actor?->id,
            'actor_role' => $actor instanceof User ? $actor->role_code : null,
            'branch_id' => $context === null ? $systemBranchId : $context->actor->branch_id,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'before_state' => $before === [] ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_state' => $after === [] ? null : json_encode($after, JSON_THROW_ON_ERROR),
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'correlation_id' => $context === null ? (string) Str::uuid() : $context->correlationId,
            'ip_address' => $context?->ipAddress,
            'user_agent' => $context?->userAgent,
            'occurred_at' => now('UTC'),
        ]);
    }

    /** @param array<string, mixed> $payload */
    public function event(
        string $type,
        ExcessBalanceModel $balance,
        array $payload,
        string $idempotencyKey,
    ): void {
        $this->events->append($type, $balance->id, [
            'distributor_id' => (string) $balance->distributor_id,
            'branch_id' => (string) $balance->branch_id,
            'relation_id' => $balance->origin_relation_id,
            'status' => $balance->status->value,
            'occurred_at' => now('UTC')->toIso8601String(),
            ...$payload,
        ], $idempotencyKey);
    }

    /** @return array<string, string> */
    public function amounts(ExcessBalanceModel $balance): array
    {
        return [
            'original' => (string) $balance->original_amount,
            'retained' => (string) $balance->retained_amount,
            'available' => (string) $balance->available_amount,
            'reserved' => (string) $balance->reserved_refund_amount,
            'applied' => (string) $balance->applied_amount,
            'refunded' => (string) $balance->refunded_amount,
        ];
    }
}
