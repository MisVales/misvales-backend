<?php

namespace App\Modules\Notification\Application\Jobs;

use App\Modules\Notification\Domain\Enums\EmailStatus;
use App\Modules\Notification\Persistence\Models\EmailDelivery;
use App\Modules\Notification\Persistence\Models\EmailDeliveryAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Job asíncrono encargado de enviar de manera segura, transaccional e idempotente
 * los correos electrónicos correspondientes a eventos críticos autorizados (M17).
 *
 * Utiliza bloqueo de base de datos (lockForUpdate) para evitar múltiples envíos simultáneos
 * de un mismo correo debido a retrasos en Workers.
 */
class SendCriticalEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $emailDeliveryId
    ) {}

    public function handle(): void
    {
        DB::transaction(function () {
            // Bloquear para evitar concurrencia
            $delivery = EmailDelivery::where('id', $this->emailDeliveryId)->lockForUpdate()->first();

            if (!$delivery || in_array($delivery->status, [EmailStatus::SENT->value, EmailStatus::PERMANENT_FAILED->value])) {
                return;
            }

            // Validar límites de intentos técnicos
            if ($delivery->attempt_count >= 3) {
                $delivery->update(['status' => EmailStatus::PERMANENT_FAILED->value, 'failed_at' => now()]);
                return;
            }

            // Iniciar intento
            $delivery->update([
                'status' => EmailStatus::SENDING->value,
                'attempt_count' => $delivery->attempt_count + 1
            ]);

            $attempt = EmailDeliveryAttempt::create([
                'id' => Str::uuid()->toString(),
                'email_delivery_id' => $delivery->id,
                'attempt_number' => $delivery->attempt_count,
            ]);

            try {
                // AQUÍ IRÍA LA INTEGRACIÓN CON TRANSPORT (Mail::send / API)
                // Usando $delivery->recipient_email_snapshot, subject, etc.
                
                // Simulación exitosa
                $success = true;
                $providerId = 'prov_' . Str::random(12);

                if ($success) {
                    $attempt->update([
                        'finished_at' => now(),
                        'result' => 'SUCCESS',
                        'provider_message_id' => $providerId
                    ]);

                    $delivery->update([
                        'status' => EmailStatus::SENT->value,
                        'sent_at' => now(),
                        'provider_message_id' => $providerId
                    ]);
                } else {
                    throw new \Exception("Temporal error from provider");
                }
            } catch (\Throwable $e) {
                $attempt->update([
                    'finished_at' => now(),
                    'result' => 'RETRYABLE_FAILURE',
                    'error_code' => 'PROVIDER_TEMP_ERROR'
                ]);

                $delivery->update([
                    'status' => EmailStatus::RETRYABLE_FAILED->value,
                    'last_error_code' => 'PROVIDER_TEMP_ERROR'
                ]);

                // Podríamos reprogramar el Job aquí si aplica, o un Worker general lo hará.
                // $this->release(60 * $delivery->attempt_count);
            }
        });
    }
}
