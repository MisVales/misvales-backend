<?php

namespace App\Modules\Access\Application\Accounts;

use App\Models\User;
use App\Modules\Access\Application\Security\OutboxDispatcher;
use App\Modules\Access\Application\Security\SecurityAuditService;

/**
 * Records security audit and outbox entries for account-related access flows.
 */
final class AccountSecurityRecorder
{
    public function __construct(
        private readonly SecurityAuditService $audit,
        private readonly OutboxDispatcher $outbox,
    ) {}

    /** @param array<string, mixed> $metadata */
    public function audit(string $rule, string $result, ?User $actor = null, ?User $target = null, array $metadata = []): void
    {
        $this->audit->record($rule, $result, $actor, $target, $metadata);
    }

    /** @param array<string, mixed> $payload */
    public function outbox(string $type, string $deduplicationKey, array $payload): void
    {
        $this->outbox->record(
            $type,
            $deduplicationKey,
            $payload,
            is_string($payload['recipient'] ?? null) ? $payload['recipient'] : null,
            is_string($payload['template'] ?? null) ? $payload['template'] : null,
        );
    }
}
