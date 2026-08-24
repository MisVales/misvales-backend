<?php

namespace App\Services\Notificaciones;

use App\Models\AuditLog;
use App\Models\EntregaNotificacion;
use App\Models\OutboxEvent;
use App\Notifications\NotificacionEventoDominio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class ProyectorNotificaciones
{
    private const CRITICAL = ['CreditLineAuthorized', 'CreditIncreaseAuthorized', 'VoucherCashed', 'RelationGenerated', 'LateFeeApplied', 'DelinquencyApplied', 'DelinquencyRemoved', 'CLIENT_TRANSFER_COMPLETED', 'DISTRIBUTOR_BRANCH_CHANGE'];

    public function __construct(private ResolvedorDestinatarios $resolver) {}

    public function proyectar(int $limit = 200): int
    {
        $count = 0;
        OutboxEvent::query()->oldest()->limit($limit)->get()->each(function (OutboxEvent $event) use (&$count): void {
            $count += $this->entregar('OUTBOX', $event->id, $event->event_type, ['payload' => $this->normalizarPayload($event->payload)]);
        });
        AuditLog::query()->oldest()->limit($limit)->get()->each(function (AuditLog $audit) use (&$count): void {
            $count += $this->entregar('AUDIT', $audit->id, $audit->event_name, ['payload' => $audit->new_value ?? [], 'actor_id' => $audit->actor_id, 'branch_id' => $audit->branch_id, 'entity_type' => $audit->entity_type, 'entity_id' => $audit->entity_id]);
        });

        return $count;
    }

    private function normalizarPayload(mixed $payload): array
    {
        for ($attempt = 0; $attempt < 2 && is_string($payload); $attempt++) {
            $decoded = json_decode($payload, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }
            $payload = $decoded;
        }

        return is_array($payload) ? $payload : [];
    }

    private function entregar(string $sourceType, string $sourceId, string $eventType, array $context): int
    {
        $delivered = 0;
        $context['event_type'] = $eventType;
        foreach ($this->resolver->resolver($context) as $recipient) {
            $delivered += DB::transaction(function () use ($sourceType, $sourceId, $eventType, $context, $recipient): int {
                $critical = in_array($eventType, self::CRITICAL, true);
                $content = $this->content($eventType, $context);
                $deliveryKey = ['source_type' => $sourceType, 'source_id' => $sourceId, 'event_type' => $eventType, 'recipient_id' => $recipient->id];
                $delivery = EntregaNotificacion::query()->lockForUpdate()->where($deliveryKey)->first();

                if ($delivery === null) {
                    $notificationId = (string) Str::uuid();
                    DB::table('notifications')->insert([
                        'id' => $notificationId,
                        'type' => NotificacionEventoDominio::class,
                        'notifiable_type' => $recipient::class,
                        'notifiable_id' => $recipient->id,
                        'data' => json_encode($content, JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $delivery = EntregaNotificacion::query()->firstOrCreate($deliveryKey, [
                        'notification_id' => $notificationId,
                        'channels' => $critical ? 'database,mail' : 'database',
                        'recipient_address' => $recipient->email,
                        'status' => 'PENDING',
                        'attempts' => 0,
                    ]);

                    if ($delivery->notification_id !== $notificationId) {
                        DB::table('notifications')->where('id', $notificationId)->delete();
                    }
                }

                if ($delivery->status === 'SENT') {
                    return 0;
                }

                $notificationExists = DB::table('notifications')->where('id', $delivery->notification_id)->exists();
                $channels = $notificationExists ? ($critical ? ['mail'] : []) : null;
                $notification = new NotificacionEventoDominio($content, $critical, $channels);
                $notification->id = $delivery->notification_id;
                $attemptedAt = now();

                try {
                    $recipient->notify($notification);
                    $delivery->forceFill([
                        'status' => 'SENT',
                        'result' => 'DELIVERED',
                        'error' => null,
                        'delivered_at' => now(),
                        'failed_at' => null,
                        'last_attempt_at' => $attemptedAt,
                        'attempts' => $delivery->attempts + 1,
                    ])->save();

                    return 1;
                } catch (Throwable $exception) {
                    DB::table('notifications')->insertOrIgnore([
                        'id' => $delivery->notification_id,
                        'type' => NotificacionEventoDominio::class,
                        'notifiable_type' => $recipient::class,
                        'notifiable_id' => $recipient->id,
                        'data' => json_encode($notification->content, JSON_THROW_ON_ERROR),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $delivery->forceFill([
                        'status' => 'FAILED',
                        'result' => 'DELIVERY_FAILED',
                        'error' => mb_substr($exception->getMessage(), 0, 2000),
                        'delivered_at' => null,
                        'failed_at' => now(),
                        'last_attempt_at' => $attemptedAt,
                        'attempts' => $delivery->attempts + 1,
                    ])->save();

                    report($exception);

                    return 0;
                }
            });
        }

        return $delivered;
    }

    private function content(string $eventType, array $context): array
    {
        $entity = $context['entity_type'] ?? $this->entity($eventType);
        $entityId = $context['entity_id'] ?? $context['payload']['voucher_id'] ?? $context['payload']['relation_id'] ?? $context['payload']['request_id'] ?? $context['payload']['subject_id'] ?? null;
        [$title, $description] = match ($eventType) {
            'PAYMENT_SURPLUS_DETECTED' => ['Pago mayor al saldo detectado', 'El pago conciliado liquidó la relación y generó un excedente.'],
            'EXCESS_CREATED' => ['Excedente pendiente de decisión', 'Hay un excedente disponible para conservar como saldo a favor o solicitar en devolución.'],
            'EXCESS_SELECTED_AS_CREDIT' => ['Saldo a favor seleccionado', 'El excedente quedó disponible para aplicarse a relaciones futuras.'],
            'EXCESS_APPLIED' => ['Saldo a favor aplicado', 'Se aplicó saldo a favor a una nueva relación.'],
            'REFUND_REQUESTED' => ['Devolución solicitada', 'Hay una solicitud de devolución pendiente de autorización.'],
            'REFUND_AUTHORIZED' => ['Devolución autorizada', 'La devolución está autorizada y pendiente de ejecución en caja.'],
            'REFUND_REJECTED' => ['Devolución rechazada', 'La solicitud fue rechazada y el importe volvió a quedar pendiente de decisión.'],
            'REFUND_CANCELLED' => ['Devolución cancelada', 'La solicitud fue cancelada y el importe volvió a quedar pendiente de decisión.'],
            'REFUND_COMPLETED' => ['Devolución completada', 'La ejecución externa de la devolución quedó registrada.'],
            default => [Str::headline($eventType), 'Existe una actualización operativa que requiere consulta en MisVales.'],
        };

        return ['title' => $title, 'description' => $description, 'event_type' => $eventType, 'entity_type' => $entity, 'entity_id' => $entityId, 'deep_link' => $this->deepLink($eventType, $entityId), 'occurred_at' => now()->toIso8601String()];
    }

    private function entity(string $event): string
    {
        return match (true) {
            str_contains(strtolower($event), 'voucher') => 'voucher',
            str_contains(strtolower($event), 'relation') => 'relation',
            str_contains(strtolower($event), 'excess'), str_contains(strtolower($event), 'refund') => 'surplus',
            str_contains(strtolower($event), 'credit') => 'credit',
            str_contains(strtolower($event), 'transfer') => 'client_transfer',
            default => 'operation',
        };
    }

    private function deepLink(string $event, ?string $id): string
    {
        $base = match (true) {
            str_contains(strtolower($event), 'voucher') => '/vales',
            str_contains(strtolower($event), 'relation') => '/relaciones',
            str_contains(strtolower($event), 'excess'), str_contains(strtolower($event), 'surplus'), str_contains(strtolower($event), 'refund') => '/relaciones-pagos/excedentes',
            str_contains(strtolower($event), 'credit') => '/distribuidoras/lineas-credito',
            str_contains(strtolower($event), 'risk'), str_contains(strtolower($event), 'delinquency') => '/riesgo',
            str_contains(strtolower($event), 'transfer'), str_contains(strtolower($event), 'reassignment'), str_contains(strtolower($event), 'coordinator') => '/transferencias',
            default => '/dashboard',
        };

        return $id ? $base.'?id='.$id : $base;
    }
}
