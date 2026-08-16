<?php

declare(strict_types=1);

namespace App\Services\Recargo;

use App\Exceptions\BusinessException;
use App\Services\ConfiguracionServicio;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RuntimeException;

final class ServicioConfiguracionRecargo
{
    private const KEYS = [
        'BUSINESS_TIMEZONE',
        'BANK_UPLOAD_DEADLINE_TIME',
        'POST_DUE_EVALUATION_TIME',
        'LATE_FEE_AMOUNT',
    ];

    public function __construct(private readonly ConfiguracionServicio $configuraciones) {}

    /** @return array{timezone: string, bank_deadline_time: string, evaluation_time: string, amount: string, configuration_versions: array<string, string>} */
    public function resolver(CarbonImmutable $at): array
    {
        try {
            $resolved = collect(self::KEYS)
                ->mapWithKeys(fn (string $key): array => [$key => $this->configuraciones->resolver($key, Carbon::instance($at))]);
        } catch (BusinessException $error) {
            throw new RuntimeException('LATE_FEE_CONFIGURATION_INCOMPLETE', previous: $error);
        }

        $timezone = $resolved['BUSINESS_TIMEZONE']['value'];
        $deadline = $resolved['BANK_UPLOAD_DEADLINE_TIME']['value'];
        $evaluation = $resolved['POST_DUE_EVALUATION_TIME']['value'];
        $amount = $resolved['LATE_FEE_AMOUNT']['value'];

        if (! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(), true)
            || ! is_string($deadline) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $deadline) !== 1
            || ! is_string($evaluation) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $evaluation) !== 1
            || ! is_numeric($amount) || bccomp((string) $amount, '0', 4) < 0) {
            throw new RuntimeException('LATE_FEE_CONFIGURATION_INCOMPLETE');
        }

        return [
            'timezone' => $timezone,
            'bank_deadline_time' => $deadline,
            'evaluation_time' => $evaluation,
            'amount' => bcadd((string) $amount, '0', 4),
            'configuration_versions' => $resolved
                ->mapWithKeys(fn (array $configuration, string $key): array => [$key => $configuration['version_id']])
                ->all(),
        ];
    }

    public function evaluacionProgramada(CarbonImmutable $at): bool
    {
        $config = $this->resolver($at);

        return $at->setTimezone($config['timezone'])->format('H:i') === $config['evaluation_time'];
    }
}
