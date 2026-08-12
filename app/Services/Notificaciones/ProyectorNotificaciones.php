<?php

namespace App\Services\Notificaciones;

use App\Models\AuditLog;
use App\Models\EntregaNotificacion;
use App\Models\OutboxEvent;
use App\Notifications\NotificacionEventoDominio;
use Illuminate\Support\Str;

final readonly class ProyectorNotificaciones
{
    private const CRITICAL = ['CreditLineAuthorized', 'CreditIncreaseAuthorized', 'VoucherCashed', 'RelationGenerated', 'LateFeeApplied', 'DelinquencyApplied', 'DelinquencyRemoved', 'CLIENT_TRANSFER_COMPLETED', 'DISTRIBUTOR_BRANCH_CHANGE'];

    public function __construct(private ResolvedorDestinatarios $resolver) {}

    public function proyectar(int $limit = 200): int
    {
        $count = 0;
        OutboxEvent::query()->oldest()->limit($limit)->get()->each(function (OutboxEvent $event) use (&$count): void {
            $count += $this->entregar('OUTBOX', $event->id, $event->event_type, ['payload' => $event->payload]);
        });
        AuditLog::query()->oldest()->limit($limit)->get()->each(function (AuditLog $audit) use (&$count): void {
            $count += $this->entregar('AUDIT', $audit->id, $audit->event_name, ['payload' => $audit->new_value ?? [], 'actor_id' => $audit->actor_id, 'branch_id' => $audit->branch_id, 'entity_type' => $audit->entity_type, 'entity_id' => $audit->entity_id]);
        });

        return $count;
    }

    private function entregar(string $sourceType, string $sourceId, string $eventType, array $context): int
    {
        $delivered = 0;
        foreach ($this->resolver->resolver($context) as $recipient) {
            $notificationId = (string) Str::uuid();
            $now = now();
            $inserted = EntregaNotificacion::query()->insertOrIgnore(['id' => (string) Str::uuid(), 'source_type' => $sourceType, 'source_id' => $sourceId, 'event_type' => $eventType, 'recipient_id' => $recipient->id, 'notification_id' => $notificationId, 'channels' => in_array($eventType, self::CRITICAL, true) ? 'database,mail' : 'database', 'delivered_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            if ($inserted === 0) {
                continue;
            }
            $notification = new NotificacionEventoDominio($this->content($eventType, $context), in_array($eventType, self::CRITICAL, true));
            $notification->id = $notificationId;
            $recipient->notify($notification);
            $delivered++;
        }

        return $delivered;
    }

    private function content(string $eventType, array $context): array
    {
        $entity = $context['entity_type'] ?? $this->entity($eventType);
        $entityId = $context['entity_id'] ?? $context['payload']['voucher_id'] ?? $context['payload']['relation_id'] ?? $context['payload']['request_id'] ?? $context['payload']['subject_id'] ?? null;

        return ['title' => Str::headline($eventType), 'description' => 'Existe una actualización operativa que requiere consulta en MisVales.', 'event_type' => $eventType, 'entity_type' => $entity, 'entity_id' => $entityId, 'deep_link' => $this->deepLink($eventType, $entityId), 'occurred_at' => now()->toIso8601String()];
    }

    private function entity(string $event): string
    {
        return match (true) {
            str_contains(strtolower($event), 'voucher') => 'voucher',
            str_contains(strtolower($event), 'relation') => 'relation',
            str_contains(strtolower($event), 'credit') => 'credit',
            str_contains(strtolower($event), 'point') => 'points',
            str_contains(strtolower($event), 'transfer') => 'client_transfer',
            default => 'operation',
        };
    }

    private function deepLink(string $event, ?string $id): string
    {
        $base = match (true) {
            str_contains(strtolower($event), 'voucher') => '/vales',
            str_contains(strtolower($event), 'relation') => '/relaciones',
            str_contains(strtolower($event), 'credit') => '/distribuidoras/lineas-credito',
            str_contains(strtolower($event), 'point') => '/puntos',
            str_contains(strtolower($event), 'risk'), str_contains(strtolower($event), 'delinquency') => '/riesgo',
            str_contains(strtolower($event), 'transfer'), str_contains(strtolower($event), 'reassignment'), str_contains(strtolower($event), 'coordinator') => '/transferencias',
            default => '/dashboard',
        };

        return $id ? $base.'?id='.$id : $base;
    }
}
