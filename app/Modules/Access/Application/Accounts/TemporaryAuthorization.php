<?php

namespace App\Modules\Access\Application\Accounts;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Infrastructure\Persistence\Models\OperationalAuthorizationToken;
use App\Modules\Access\Infrastructure\Persistence\Models\ReauthAuthorization;

final class TemporaryAuthorization
{
    public function consumeReauth(User $actor, string $plainToken, string $action, ?string $recordId = null): void
    {
        $authorization = ReauthAuthorization::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->lockForUpdate()
            ->first();

        if ($authorization === null
            || $authorization->user_id !== $actor->id
            || $authorization->action !== $action
            || ($recordId !== null && $authorization->record_id !== $recordId)
            || $authorization->used_at !== null
            || $authorization->revoked_at !== null
            || $authorization->expires_at->isPast()) {
            throw new AccessRuleViolation('La autorización temporal no es válida.', 403);
        }

        $authorization->forceFill(['used_at' => now()])->save();
    }

    /** @param array<string, mixed> $capturedFields */
    public function consumeOperational(User $actor, string $plainToken, string $action, array $capturedFields): void
    {
        $authorization = OperationalAuthorizationToken::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->lockForUpdate()
            ->first();

        if ($authorization === null
            || $authorization->requested_by !== $actor->id
            || $authorization->authorized_by !== $actor->id
            || $authorization->action !== $action
            || $authorization->used_at !== null
            || $authorization->revoked_at !== null
            || $authorization->expires_at->isPast()
            || $this->canonical($authorization->authorized_fields) !== $this->canonical($capturedFields)) {
            throw new AccessRuleViolation('La autorización operativa no coincide con la operación.', 403);
        }

        $authorization->forceFill(['used_at' => now(), 'executed_by' => $actor->id])->save();
    }

    /** @param array<string, mixed> $fields */
    private function canonical(array $fields): string
    {
        ksort($fields);

        return json_encode($fields, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
