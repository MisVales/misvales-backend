<?php

namespace App\Services\Audit;

use App\Helpers\AuditHelper;
use App\Models\SecurityEvent;
use Illuminate\Http\Request;

class SecurityAuditService
{
    /**
     * Registra un evento de seguridad de forma asíncrona (si hubiera colas) o síncrona.
     */
    public function log(Request $request, array $data): void
    {
        $device = $this->parseDevice($request->userAgent());

        $metadata = $data['metadata'] ?? [];
        $metadata['device'] = $device;

        // En un entorno de producción masivo, esto debería despacharse a un Job.
        // Por ahora, se guarda directamente en la BD.
        $event = SecurityEvent::create([
            'user_id' => $data['user_id'] ?? $request->user()?->id,
            'actor_user_id' => $data['actor_user_id'] ?? $request->user()?->id,
            'branch_id' => $data['branch_id'] ?? null,
            'auth_session_id' => $data['auth_session_id'] ?? $request->attributes->get('auth_session')?->id,
            'event_type' => $data['event_type'],
            'severity' => $data['severity'] ?? 'INFO',
            'outcome' => $data['outcome'] ?? 'SUCCESS',
            'entity_type' => $data['entity_type'] ?? null,
            'entity_id' => $data['entity_id'] ?? null,
            'request_id' => $request->attributes->get('request_id'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => $metadata,
            'occurred_at' => now(),
        ]);

        $previousValues = $data['previous'] ?? $metadata['previous_values'] ?? null;
        $newValues = $data['new'] ?? $metadata['new_values'] ?? $metadata;
        $reason = $data['reason'] ?? $metadata['reason'] ?? null;

        AuditHelper::log(
            eventName: $data['event_type'],
            entityType: $data['entity_type'] ?? 'SecurityEvent',
            entityId: $data['entity_id'] ?? $event->id,
            actorId: $data['actor_user_id'] ?? $request->user()?->id,
            branchId: $data['branch_id'] ?? null,
            previous: is_array($previousValues) && count($previousValues) > 0 ? $previousValues : null,
            new: is_array($newValues) && count($newValues) > 0 ? $newValues : $metadata,
            reason: $reason,
            result: $data['outcome'] ?? 'SUCCESS',
            evidence: ['security_event_id' => $event->id, 'metadata' => $metadata],
        );
    }

    public function parseDevice(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'Dispositivo Desconocido';
        }

        $os = 'Desconocido';
        if (preg_match('/windows/i', $userAgent)) {
            $os = 'Windows';
        } elseif (preg_match('/mac/i', $userAgent)) {
            $os = 'Mac';
        } elseif (preg_match('/linux/i', $userAgent)) {
            $os = 'Linux';
        } elseif (preg_match('/android/i', $userAgent)) {
            $os = 'Android';
        } elseif (preg_match('/iphone|ipad/i', $userAgent)) {
            $os = 'iOS';
        }

        $browser = 'Navegador Desconocido';
        if (preg_match('/edge/i', $userAgent)) {
            $browser = 'Edge';
        } elseif (preg_match('/chrome/i', $userAgent)) {
            $browser = 'Chrome';
        } elseif (preg_match('/firefox/i', $userAgent)) {
            $browser = 'Firefox';
        } elseif (preg_match('/safari/i', $userAgent)) {
            $browser = 'Safari';
        }

        return "{$os} - {$browser}";
    }
}
