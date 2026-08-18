<?php

namespace App\Jobs;

use App\Models\ConfigurationVersion;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Notifications\CriticalConfigurationChanged;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class DispatchOutboxEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [10, 30, 60];

    private OutboxEvent $event;

    public function __construct(OutboxEvent $event)
    {
        $this->event = $event;
    }

    public function handle(): void
    {
        $this->event->refresh();
        if ($this->event->status !== 'PENDING') {
            return;
        }

        $lock = Cache::lock('outbox_event_'.$this->event->id, 60);

        if (! $lock->get()) {
            return;
        }

        try {
            if ($this->event->event_type === 'ConfigurationPublished') {
                $this->processConfigurationPublished();
            }

            $this->event->update([
                'status' => 'PROCESSED',
                'processed_at' => now(),
            ]);
        } catch (\Exception $e) {
            $this->event->update([
                'status' => 'FAILED',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function processConfigurationPublished(): void
    {
        $key = $this->event->payload['configuration_key'];
        $versionId = $this->event->payload['version_id'];

        $version = ConfigurationVersion::with('definition')->find($versionId);
        if (! $version) {
            return;
        }

        // Punto 90: Evitar información sensible
        $isSensitive = $version->definition->is_sensitive;
        $displayValue = $isSensitive ? '********' : (string) $version->value;

        // Reglas de enrutamiento (Punto 88)
        $rolesAfectados = ['general_manager', 'branch_manager'];

        $keyUpper = strtoupper($key);
        if (in_array($keyUpper, ['DIA_CORTE_GLOBAL', 'FECHA_VENCIMIENTO', 'CARGA_BANCARIA', 'REGLAS_CARGA_BANCARIA', 'DIA_CORTE', 'VENCIMIENTO'])) {
            $rolesAfectados[] = 'cashier';
        }

        if (in_array($keyUpper, ['LIMITE_CREDITO', 'PORCENTAJE_INTERES', 'REGLAS_OPERATIVAS', 'COMISION_PRESTAMO', 'CREDITO_MAXIMO'])) {
            $rolesAfectados[] = 'coordinator';
        }

        $users = User::whereHas('roleScopes', function ($q) use ($rolesAfectados) {
            $q->whereHas('role', function ($q2) use ($rolesAfectados) {
                $q2->whereIn('name', $rolesAfectados);
            });
        })->get();

        if ($users->isNotEmpty()) {
            Notification::send($users, new CriticalConfigurationChanged($key, $displayValue));
        }
    }
}
