<?php

namespace App\Modules\Access\Application\Security;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\SecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

final readonly class SecurityAuditService
{
    public function __construct(private SecretSanitizer $sanitizer) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $eventType,
        string $result,
        ?User $actor = null,
        ?User $target = null,
        array $context = [],
    ): SecurityEvent {
        $request = app()->bound('request') ? request() : null;
        $occurredAt = now('UTC');
        $correlationId = $this->correlationId($request, $context);
        $accessToken = $actor?->currentAccessToken();
        $sessionId = $accessToken instanceof PersonalAccessToken ? $accessToken->auth_session_id : null;

        $eventUuid = (string) Str::uuid();

        return SecurityEvent::query()->create([
            'public_id' => $eventUuid,
            'event_uuid' => $eventUuid,
            'event_type' => $eventType,
            'actor_user_id' => $actor?->id,
            'target_user_id' => $target?->id,
            'requester_user_id' => $context['requester_user_id'] ?? $actor?->id,
            'authorizer_user_id' => $context['authorizer_user_id'] ?? null,
            'executor_user_id' => $context['executor_user_id'] ?? null,
            'auth_session_id' => $context['session_id'] ?? $sessionId,
            'role_code' => $context['role_code'] ?? $actor?->role_code,
            'branch_id' => $context['branch_id'] ?? $actor?->branch_id,
            'application' => $context['application'] ?? $request?->header('X-Application-Id'),
            'rule' => $context['rule'] ?? $eventType,
            'rule_code' => $context['rule_code'] ?? $eventType,
            'scope' => ($context['branch_id'] ?? $actor?->branch_id) === null ? 'GLOBAL' : 'BRANCH',
            'result' => $result,
            'occurred_at' => $occurredAt,
            'display_timezone' => (string) config('access.display_timezone', 'America/Monterrey'),
            'ip_address' => $context['ip_address'] ?? $request?->ip(),
            'device_id' => $context['device_id'] ?? $request?->cookie('mv_device'),
            'resource_type' => $context['resource_type'] ?? null,
            'resource_id' => $context['resource_id'] ?? null,
            'before_state' => $this->arrayOrNull($context['before'] ?? null),
            'after_state' => $this->arrayOrNull($context['after'] ?? null),
            'risk_level' => $context['risk_level'] ?? null,
            'counter' => $context['counter'] ?? null,
            'reason' => $context['reason'] ?? null,
            'correlation_id' => $correlationId,
            'metadata' => $this->sanitizer->sanitize(
                is_array($context['metadata'] ?? null) ? $context['metadata'] : $context,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function arrayOrNull(mixed $value): ?array
    {
        return is_array($value) ? $this->sanitizer->sanitize($value) : null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function correlationId(?Request $request, array $context): string
    {
        $candidate = $context['correlation_id'] ?? $request?->attributes->get('correlation_id');

        return is_string($candidate) && Str::isUuid($candidate) ? $candidate : (string) Str::uuid();
    }
}
