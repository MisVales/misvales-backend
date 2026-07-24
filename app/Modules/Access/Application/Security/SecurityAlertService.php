<?php

namespace App\Modules\Access\Application\Security;

use App\Models\User;
use App\Modules\Access\Domain\Accounts\AccessRuleViolation;
use App\Modules\Access\Infrastructure\Persistence\Models\SecurityAlert;
use App\Modules\Access\Infrastructure\Persistence\Models\SecurityEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final readonly class SecurityAlertService
{
    public function __construct(private SecurityAuditService $audit) {}

    public function open(
        SecurityEvent $event,
        ?User $affectedUser,
        ?string $branchId,
        string $severity,
        string $type,
        string $summary,
    ): SecurityAlert {
        return SecurityAlert::query()->create([
            'public_id' => (string) Str::uuid(),
            'security_event_id' => $event->id,
            'affected_user_id' => $affectedUser?->id,
            'branch_id' => $branchId,
            'severity' => $severity,
            'type' => $type,
            'state' => 'OPEN',
            'summary' => $summary,
        ]);
    }

    /** @return Builder<SecurityAlert> */
    public function visibleTo(User $actor): Builder
    {
        $query = SecurityAlert::query()->latest();

        return match ((string) $actor->role_code) {
            'GENERAL_MANAGER', 'ADMIN' => $query,
            'BRANCH_MANAGER', 'SUCURSAL_MANAGER' => $query->where('branch_id', $actor->branch_id),
            default => $query->where('affected_user_id', $actor->id),
        };
    }

    public function acknowledge(User $actor, SecurityAlert $alert): SecurityAlert
    {
        if ((string) $actor->role_code !== 'GENERAL_MANAGER') {
            throw new AccessRuleViolation(
                'El rol no puede ejecutar acciones sobre alertas.',
                403,
                'ALERT_ACTION_FORBIDDEN',
            );
        }

        $alert->forceFill([
            'state' => 'ACKNOWLEDGED',
            'acknowledged_by_user_id' => $actor->id,
            'acknowledged_at' => now(),
        ])->save();
        $this->audit->record('SECURITY_ALERT_ACKNOWLEDGED', 'SUCCESS', $actor, null, [
            'resource_type' => 'security_alerts',
            'resource_id' => $alert->public_id,
        ]);

        return $alert;
    }

    public function requestAction(User $actor, SecurityAlert $alert, string $reason): SecurityAlert
    {
        if (! in_array((string) $actor->role_code, ['BRANCH_MANAGER', 'SUCURSAL_MANAGER'], true)
            || $actor->branch_id === null
            || $actor->branch_id !== $alert->branch_id) {
            throw new AccessRuleViolation(
                'La cuenta no puede solicitar acción sobre esta alerta.',
                403,
                'ALERT_ACTION_FORBIDDEN',
            );
        }

        $alert->forceFill([
            'state' => 'ACTION_REQUESTED',
            'action_request_reason' => $reason,
        ])->save();
        $this->audit->record('SECURITY_ALERT_ACTION_REQUESTED', 'SUCCESS', $actor, null, [
            'branch_id' => $alert->branch_id,
            'resource_type' => 'security_alerts',
            'resource_id' => $alert->public_id,
            'reason' => $reason,
        ]);

        return $alert;
    }
}
