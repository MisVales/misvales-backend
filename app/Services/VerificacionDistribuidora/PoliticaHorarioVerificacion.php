<?php

namespace App\Services\VerificacionDistribuidora;

use App\Models\ConfigurationDefinition;
use Carbon\CarbonImmutable;

final class PoliticaHorarioVerificacion
{
    public const START_KEY = 'VERIFICATION_START_TIME';

    public const MAX_KEY = 'VERIFICATION_MAX_START_TIME';

    public function obtener(?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $values = ConfigurationDefinition::query()
            ->whereIn('key', [self::START_KEY, self::MAX_KEY])
            ->with(['versions' => fn ($query) => $query
                ->where('status', 'PUBLISHED')
                ->where('effective_from', '<=', $at)
                ->where(fn ($effective) => $effective->whereNull('effective_to')->orWhere('effective_to', '>', $at))
                ->orderByDesc('effective_from')])
            ->get()
            ->mapWithKeys(fn (ConfigurationDefinition $definition) => [
                $definition->key => $definition->versions->first()?->value,
            ]);

        $start = $this->normalizeTime((string) ($values[self::START_KEY] ?? '08:00'));
        $max = $this->normalizeTime((string) ($values[self::MAX_KEY] ?? '23:45'));

        if ($start > $max) {
            throw new \LogicException('La hora inicial de verificación no puede ser posterior a la hora máxima.');
        }

        return [
            'start_time' => $start,
            'max_start_time' => $max,
            'timezone' => config('app.timezone'),
            'slot_minutes' => 15,
        ];
    }

    public function validar(CarbonImmutable $scheduled): void
    {
        $policy = $this->obtener();
        $local = $scheduled->setTimezone($policy['timezone']);
        $time = $local->format('H:i');

        if ($time < $policy['start_time'] || $time > $policy['max_start_time']) {
            throw new \App\Exceptions\BusinessException(
                'VERIFICATION_TIME_OUTSIDE_GLOBAL_SCHEDULE',
                "La hora debe estar entre {$policy['start_time']} y {$policy['max_start_time']}, según la configuración global vigente.",
                422,
            );
        }

        if (((int) $local->format('i')) % $policy['slot_minutes'] !== 0) {
            throw new \App\Exceptions\BusinessException('VERIFICATION_TIME_INVALID_SLOT', 'La hora debe corresponder a un bloque de 15 minutos.', 422);
        }
    }

    public function validarHoraDeInicio(CarbonImmutable $now): void
    {
        $policy = $this->obtener($now);
        $time = $now->setTimezone($policy['timezone'])->format('H:i');
        if ($time < $policy['start_time'] || $time > $policy['max_start_time']) {
            throw new \App\Exceptions\BusinessException(
                'VERIFICATION_START_OUTSIDE_GLOBAL_SCHEDULE',
                "La verificación sólo puede iniciarse entre {$policy['start_time']} y {$policy['max_start_time']}, según la configuración global vigente.",
                409,
            );
        }
    }

    private function normalizeTime(string $value): string
    {
        $parsed = CarbonImmutable::createFromFormat('!H:i:s', strlen($value) === 5 ? $value.':00' : $value);

        return $parsed->format('H:i');
    }
}
