<?php

namespace App\Modules\Access\Application\Security;

use App\Modules\Access\Infrastructure\Persistence\Models\NotificationDelivery;
use App\Modules\Access\Infrastructure\Persistence\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class OutboxProcessor
{
    public function __construct(
        private SecurityNotificationSender $sender,
        private SecretSanitizer $sanitizer,
    ) {}

    public function process(int $eventId): void
    {
        $claimed = DB::transaction(function () use ($eventId): ?array {
            $event = OutboxEvent::query()->lockForUpdate()->find($eventId);
            if ($event === null || $event->processed_at !== null || $event->state === 'SENT') {
                return null;
            }
            if ($event->recipient === null || $event->template === null) {
                $event->forceFill([
                    'state' => 'SKIPPED',
                    'result' => 'NO_RECIPIENT',
                    'processed_at' => now(),
                ])->save();

                return null;
            }
            if ($event->state === 'PROCESSING'
                && $event->last_attempt_at !== null
                && $event->last_attempt_at->isAfter(now()->subMinutes(5))) {
                return null;
            }

            $idempotencyKey = hash('sha256', implode('|', [
                $event->event_uuid,
                $event->recipient,
                $event->template,
            ]));
            $delivery = NotificationDelivery::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'outbox_event_id' => $event->id,
                    'recipient' => $event->recipient,
                    'template' => $event->template,
                    'state' => 'PENDING',
                ],
            );
            if ($delivery->state === 'SENT') {
                $event->forceFill([
                    'state' => 'SENT',
                    'result' => 'DELIVERED',
                    'processed_at' => $delivery->sent_at ?? now(),
                ])->save();

                return null;
            }

            $event->forceFill([
                'state' => 'PROCESSING',
                'attempts' => $event->attempts + 1,
                'last_attempt_at' => now(),
                'result' => 'ATTEMPTING',
            ])->save();
            $delivery->forceFill([
                'state' => 'PROCESSING',
                'attempts' => $delivery->attempts + 1,
                'last_attempt_at' => now(),
            ])->save();

            return [
                'event_id' => $event->id,
                'delivery_id' => $delivery->id,
                'recipient' => $event->recipient,
                'template' => $event->template,
                'payload' => $event->payload,
                'idempotency_key' => $idempotencyKey,
            ];
        });

        if ($claimed === null) {
            return;
        }

        try {
            $providerReference = $this->sender->send(
                $claimed['recipient'],
                $claimed['template'],
                $claimed['payload'],
                $claimed['idempotency_key'],
            );
            DB::transaction(function () use ($claimed, $providerReference): void {
                NotificationDelivery::query()->whereKey($claimed['delivery_id'])->update([
                    'state' => 'SENT',
                    'sent_at' => now(),
                    'provider_reference' => $providerReference,
                    'result' => 'DELIVERED',
                    'updated_at' => now(),
                ]);
                OutboxEvent::query()->whereKey($claimed['event_id'])->update([
                    'state' => 'SENT',
                    'result' => 'DELIVERED',
                    'processed_at' => now(),
                    'last_error' => null,
                    'updated_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            DB::transaction(function () use ($claimed, $exception): void {
                $event = OutboxEvent::query()->lockForUpdate()->findOrFail($claimed['event_id']);
                $delaySeconds = min(3600, 60 * (2 ** max(0, $event->attempts - 1)));
                $safeError = $this->sanitizer->containsSecret($exception->getMessage())
                    ? 'Notification provider failure.'
                    : mb_substr($exception->getMessage(), 0, 1000);
                $event->forceFill([
                    'state' => 'RETRY',
                    'result' => 'FAILED',
                    'last_error' => $safeError,
                    'next_attempt_at' => now()->addSeconds($delaySeconds),
                ])->save();
                NotificationDelivery::query()->whereKey($claimed['delivery_id'])->update([
                    'state' => 'RETRY',
                    'result' => 'FAILED',
                    'updated_at' => now(),
                ]);
            });

            throw new RuntimeException('Security notification delivery failed.', previous: $exception);
        }
    }
}
