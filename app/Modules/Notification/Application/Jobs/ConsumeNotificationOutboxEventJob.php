<?php

namespace App\Modules\Notification\Application\Jobs;

use App\Modules\Notification\Application\Resolvers\RecipientResolver;
use App\Modules\Notification\Application\Services\EventProcessor;
use App\Modules\Notification\Domain\Enums\ProcessingStatus;
use App\Modules\Notification\Persistence\Models\EmailDelivery;
use App\Modules\Notification\Persistence\Models\Notification;
use App\Modules\Notification\Persistence\Models\NotificationEvent;
use App\Modules\Notification\Persistence\Models\NotificationRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsumeNotificationOutboxEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly array $outboxPayload
    ) {}

    public function handle(EventProcessor $processor, RecipientResolver $resolver): void
    {
        $eventCode = $this->outboxPayload['event_code'];

        // 1. Guardar o recuperar evento base (Idempotencia)
        $notificationEvent = NotificationEvent::firstOrCreate(
            ['outbox_event_id' => $this->outboxPayload['event_id']],
            [
                'id' => Str::uuid()->toString(),
                'event_code' => $eventCode,
                'event_version' => $this->outboxPayload['event_version'] ?? 1,
                'aggregate_type' => $this->outboxPayload['aggregate_type'] ?? 'unknown',
                'aggregate_id' => $this->outboxPayload['aggregate_id'] ?? Str::uuid(),
                'branch_id' => $this->outboxPayload['branch_id'] ?? null,
                'actor_user_id' => $this->outboxPayload['actor_user_id'] ?? null,
                'authorizer_user_id' => $this->outboxPayload['authorizer_user_id'] ?? null,
                'correlation_id' => $this->outboxPayload['correlation_id'] ?? null,
                'causation_id' => $this->outboxPayload['causation_id'] ?? null,
                'occurred_at' => $this->outboxPayload['occurred_at'] ?? now(),
                'payload_snapshot' => $this->outboxPayload['payload'] ?? [],
                'processing_status' => ProcessingStatus::RECEIVED->value,
            ]
        );

        if (in_array($notificationEvent->processing_status, [ProcessingStatus::PROCESSED->value, ProcessingStatus::UNSUPPORTED->value])) {
            return; // Ya procesado o no soportado
        }

        if (!$processor->isSupported($eventCode)) {
            $notificationEvent->update(['processing_status' => ProcessingStatus::UNSUPPORTED->value]);
            return;
        }

        DB::transaction(function () use ($notificationEvent, $resolver) {
            // Actualizar a procesando (simbolico si es transacción rápida)
            $notificationEvent->update(['processing_status' => ProcessingStatus::PROCESSING->value]);

            // 2. Resolver destinatarios
            $recipients = $resolver->resolve($notificationEvent->event_code, $notificationEvent->payload_snapshot);

            foreach ($recipients as $recipientData) {
                // 3. Idempotencia en Destinatario
                $recipient = NotificationRecipient::firstOrCreate(
                    [
                        'notification_event_id' => $notificationEvent->id,
                        'recipient_key' => $recipientData['recipient_key']
                    ],
                    array_merge($recipientData, [
                        'id' => Str::uuid()->toString(),
                        'resolved_at' => now(),
                    ])
                );

                // 4. Crear Notificación in-app si es usuario (Idempotencia)
                if ($recipient->recipient_type === 'USER' && $recipient->user_id) {
                    Notification::firstOrCreate(
                        [
                            'notification_event_id' => $notificationEvent->id,
                            'user_id' => $recipient->user_id
                        ],
                        [
                            'id' => Str::uuid()->toString(),
                            'notification_recipient_id' => $recipient->id,
                            'event_code' => $notificationEvent->event_code,
                            'title' => 'Notificación ' . $notificationEvent->event_code,
                            'summary' => 'Detalle de evento ' . $notificationEvent->event_code,
                            'occurred_at' => $notificationEvent->occurred_at,
                        ]
                    );
                }

                // 5. Crear Email Delivery (Idempotencia)
                if ($recipient->email_snapshot) {
                    $email = EmailDelivery::firstOrCreate(
                        [
                            'notification_event_id' => $notificationEvent->id,
                            'notification_recipient_id' => $recipient->id
                        ],
                        [
                            'id' => Str::uuid()->toString(),
                            'event_code' => $notificationEvent->event_code,
                            'recipient_email_snapshot' => $recipient->email_snapshot,
                            'subject_snapshot' => 'Aviso ' . $notificationEvent->event_code,
                            'message_key' => 'msg_' . Str::random(10) . '_' . time(),
                        ]
                    );

                    // Despachar el Job del correo si está pendiente
                    if ($email->wasRecentlyCreated || $email->status === 'PENDING') {
                        $email->update(['status' => 'QUEUED', 'queued_at' => now()]);
                        SendCriticalEmailJob::dispatch($email->id);
                    }
                }
            }

            // Marcar como procesado
            $notificationEvent->update([
                'processing_status' => ProcessingStatus::PROCESSED->value,
                'processed_at' => now()
            ]);
        });
    }
}
