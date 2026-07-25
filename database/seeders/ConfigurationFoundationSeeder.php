<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Configuration\Domain\Enums\ConfigurationKey;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationDefinitionModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationVersionModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeder idempotente de definiciones y valores iniciales de M03 (C02).
 *
 * - Crea definiciones de configuración de manera idempotente.
 * - Crea únicamente los valores iniciales confirmados.
 * - No crea categorías, productos ni periodos de canje.
 * - No sobrescribe versiones publicadas existentes.
 * - No utiliza valores de los ejemplos financieros como datos operativos.
 */
class ConfigurationFoundationSeeder extends Seeder
{
    public function run(): void
    {
        $systemUserId = $this->resolveSystemUserId();
        $now = CarbonImmutable::now();

        foreach (ConfigurationKey::cases() as $key) {
            $definition = ConfigurationDefinitionModel::query()->firstOrCreate(
                ['key' => $key->value],
                [
                    'public_id' => (string) Str::uuid(),
                    'type' => $key->type()->value,
                    'is_administrable' => $key->isAdministrable(),
                ],
            );

            // Solo crear la versión inicial si la clave tiene un valor confirmado
            // y la definición no tiene ninguna versión publicada
            if ($key->hasInitialValue() && ! $this->hasPublishedVersion($definition)) {
                ConfigurationVersionModel::query()->firstOrCreate(
                    [
                        'definition_id' => $definition->id,
                        'version_number' => 1,
                    ],
                    [
                        'public_id' => (string) Str::uuid(),
                        'value' => $key->initialValue(),
                        'status' => VersionStatus::PUBLISHED->value,
                        'effective_from' => $now,
                        'created_by' => $systemUserId,
                        'published_by' => $systemUserId,
                        'published_at' => $now,
                        'reason' => 'Valor inicial del sistema conforme a la especificación M03.',
                    ],
                );
            }
        }
    }

    /**
     * Verifica si ya existe una versión publicada para la definición.
     */
    private function hasPublishedVersion(ConfigurationDefinitionModel $definition): bool
    {
        return ConfigurationVersionModel::query()
            ->where('definition_id', $definition->id)
            ->where('status', VersionStatus::PUBLISHED->value)
            ->exists();
    }

    /**
     * Resuelve el ID del usuario sistema (gerente general) para registrar
     * como creador y publicador de los valores iniciales.
     */
    private function resolveSystemUserId(): int
    {
        /** @var \App\Models\User|null $user */
        $user = \App\Models\User::query()
            ->whereHas('role', function ($q): void {
                $q->where('code', 'GENERAL_MANAGER');
            })
            ->first();

        // Usa el primer usuario disponible si no existe un gerente general
        return $user?->id ?? (\App\Models\User::query()->first()?->id ?? 1);
    }
}
