<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Domain\ValueObjects;

use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use JsonSerializable;

/**
 * Periodo de pago anticipado como objeto tipado (C05).
 *
 * Define la ventana de pago anticipado respecto de la fecha límite,
 * incluyendo desplazamientos en días y horas locales exactas.
 *
 * Al generar una relación, M10 transforma esta regla en fechas concretas.
 */
final readonly class EarlyPaymentPeriod implements JsonSerializable
{
    /**
     * @param  int  $startOffsetDays  Desplazamiento del inicio respecto de la fecha límite (negativo = antes).
     * @param  string  $startTime  Hora local de inicio (HH:MM:SS).
     * @param  int  $endOffsetDays  Desplazamiento del fin respecto de la fecha límite.
     * @param  string  $endTime  Hora local de fin (HH:MM:SS).
     * @param  string  $timezone  Zona operativa.
     */
    public function __construct(
        public int $startOffsetDays,
        public string $startTime,
        public int $endOffsetDays,
        public string $endTime,
        public string $timezone,
    ) {
        // Validar horas
        new TimeOfDay($startTime);
        new TimeOfDay($endTime);

        // Validar zona horaria
        new TimezoneValue($timezone);

        // El periodo de inicio debe ser anterior o igual al periodo de fin
        if ($startOffsetDays > $endOffsetDays) {
            throw ConfigurationException::valueInvalid(
                'El desplazamiento de inicio no puede ser posterior al desplazamiento de fin.'
            );
        }

        if ($startOffsetDays === $endOffsetDays && $startTime >= $endTime) {
            throw ConfigurationException::valueInvalid(
                'El inicio del periodo anticipado debe ser anterior al fin cuando los desplazamientos son iguales.'
            );
        }
    }

    /**
     * Construye el periodo desde la representación JSON persistida.
     *
     *
     * @throws ConfigurationException Si el JSON no es válido o la estructura es incorrecta.
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ConfigurationException::valueInvalid(
                'El periodo anticipado no es un JSON válido: '.$e->getMessage()
            );
        }

        if (! is_array($data)) {
            throw ConfigurationException::valueInvalid(
                'El periodo anticipado debe ser un objeto con estructura definida.'
            );
        }

        $required = ['start_offset_days', 'start_time', 'end_offset_days', 'end_time', 'timezone'];
        foreach ($required as $field) {
            if (! array_key_exists($field, $data)) {
                throw ConfigurationException::valueInvalid(
                    "El periodo anticipado requiere el campo «{$field}»."
                );
            }
        }

        if (! is_int($data['start_offset_days']) || ! is_int($data['end_offset_days'])) {
            throw ConfigurationException::valueInvalid(
                'Los desplazamientos deben ser enteros.'
            );
        }

        if (! is_string($data['start_time'])
            || ! is_string($data['end_time'])
            || ! is_string($data['timezone'])) {
            throw ConfigurationException::valueInvalid(
                'Las horas y la zona horaria deben ser cadenas.'
            );
        }

        return new self(
            startOffsetDays: $data['start_offset_days'],
            startTime: $data['start_time'],
            endOffsetDays: $data['end_offset_days'],
            endTime: $data['end_time'],
            timezone: $data['timezone'],
        );
    }

    public function toJson(): string
    {
        return json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR);
    }

    /**
     * @return array{start_offset_days: int, start_time: string, end_offset_days: int, end_time: string, timezone: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'start_offset_days' => $this->startOffsetDays,
            'start_time' => $this->startTime,
            'end_offset_days' => $this->endOffsetDays,
            'end_time' => $this->endTime,
            'timezone' => $this->timezone,
        ];
    }
}
