<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Services;

use App\Modules\Voucher\Application\DTOs\OperationMetadata;
use App\Modules\Voucher\Application\Security\VoucherActorContext;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherAuditModel;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherOperationHistoryModel;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherOutboxEventModel;
use Illuminate\Support\Str;

/** Persiste historial, auditoría y outbox sin copiar secretos. */
final readonly class VoucherRecorder
{
    public function __construct(private IdempotencyService $idempotency) {}

    /** @param array<string, mixed> $context */
    public function operation(
        string $voucherId,
        string $operation,
        ?string $before,
        ?string $after,
        VoucherActorContext $actor,
        OperationMetadata $metadata,
        array $context = [],
    ): void {
        $history = new VoucherOperationHistoryModel;
        $history->forceFill([
            'voucher_id' => $voucherId,
            'operation' => $operation,
            'status_before' => $before,
            'status_after' => $after,
            'actor_id' => $actor->userId,
            'branch_id' => $actor->branchId,
            'protected_context' => $context,
            'request_id' => $metadata->requestId,
            'idempotency_key_hmac' => $this->idempotency->keyHmac($metadata->idempotencyKey),
            'occurred_at' => now('UTC'),
        ])->save();
        $this->audit($operation, 'SUCCESS', $voucherId, $actor, $metadata, $context);
    }

    /** @param array<string, mixed> $payload */
    public function event(string $event, string $aggregateId, string $eventKey, array $payload): void
    {
        $model = new VoucherOutboxEventModel;
        $model->forceFill([
            'aggregate_id' => $aggregateId,
            'event_type' => $event,
            'event_key' => $eventKey,
            'payload' => $payload,
            'occurred_at' => now('UTC'),
        ])->save();
    }

    /** @param array<string, mixed> $context */
    public function audit(
        string $event,
        string $result,
        ?string $voucherId,
        ?VoucherActorContext $actor,
        OperationMetadata $metadata,
        array $context = [],
        ?string $errorCode = null,
    ): void {
        $model = new VoucherAuditModel;
        $model->forceFill([
            'event_type' => $event,
            'result' => $result,
            'voucher_id' => $voucherId,
            'actor_id' => $actor?->userId,
            'effective_role' => $actor?->role->value,
            'branch_id' => $actor?->branchId,
            'request_id' => Str::isUuid($metadata->requestId) ? $metadata->requestId : null,
            'ip_hash' => $metadata->ip === null ? null : hash('sha256', $metadata->ip),
            'user_agent_hash' => $metadata->userAgent === null ? null : hash('sha256', $metadata->userAgent),
            'idempotency_key_hmac' => $metadata->idempotencyKey === ''
                ? null
                : $this->idempotency->keyHmac($metadata->idempotencyKey),
            'protected_context' => $context,
            'error_code' => $errorCode,
            'occurred_at' => now('UTC'),
        ])->save();
    }
}
