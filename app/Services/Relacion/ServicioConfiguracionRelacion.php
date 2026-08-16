<?php

declare(strict_types=1);

namespace App\Services\Relacion;

use App\Exceptions\BusinessException;
use App\Services\ConfiguracionServicio;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use RuntimeException;

final class ServicioConfiguracionRelacion
{
    private const KEYS = [
        'BUSINESS_TIMEZONE',
        'PAYMENT_DAYS_AFTER_CUT',
        'PAYMENT_DEADLINE_TIME',
        'EARLY_PAYMENT_PERIOD',
        'RELATION_PAYMENT_BANK',
    ];

    public function __construct(private readonly ConfiguracionServicio $configuraciones) {}

    /** @return array{timezone: string, cutoff_day: int, cutoff_time: string} */
    public function programacionCorte(CarbonImmutable $at): array
    {
        $resolved = $this->resolverClaves(['BUSINESS_TIMEZONE', 'CUT_DAY_OF_MONTH', 'CUT_TIME'], $at);
        $timezone = $resolved['BUSINESS_TIMEZONE']['value'];
        $day = $resolved['CUT_DAY_OF_MONTH']['value'];
        $time = $resolved['CUT_TIME']['value'];

        if (! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(), true)
            || filter_var($day, FILTER_VALIDATE_INT) === false || (int) $day < 1 || (int) $day > 28
            || ! is_string($time) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
            throw new RuntimeException('RELATION_CONFIGURATION_INCOMPLETE');
        }

        return ['timezone' => $timezone, 'cutoff_day' => (int) $day, 'cutoff_time' => $time];
    }

    public function corteProgramado(CarbonImmutable $at): ?CarbonImmutable
    {
        $schedule = $this->programacionCorte($at);
        $local = $at->setTimezone($schedule['timezone']);

        if ($local->day !== $schedule['cutoff_day'] || $local->format('H:i') !== $schedule['cutoff_time']) {
            return null;
        }

        return $local;
    }

    /**
     * @return array{
     *     timezone: string,
     *     payment_deadline_days: int,
     *     payment_deadline_time: string,
     *     early_payment_period: array{start: int, end: int},
     *     bank: array{name: string, beneficiary: string, agreement: string, clabe: string},
     *     configuration_versions: array<string, string>
     * }
     */
    public function resolver(CarbonImmutable $at): array
    {
        $resolved = $this->resolverClaves(self::KEYS, $at);

        $timezone = $resolved['BUSINESS_TIMEZONE']['value'];
        $deadlineDays = $resolved['PAYMENT_DAYS_AFTER_CUT']['value'];
        $deadlineTime = $resolved['PAYMENT_DEADLINE_TIME']['value'];
        $period = $resolved['EARLY_PAYMENT_PERIOD']['value'];
        $bank = $resolved['RELATION_PAYMENT_BANK']['value'];

        if (! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(), true)
            || filter_var($deadlineDays, FILTER_VALIDATE_INT) === false || (int) $deadlineDays < 1
            || ! is_string($deadlineTime) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $deadlineTime) !== 1
            || ! $this->periodoValido($period)
            || ! $this->bancoValido($bank)) {
            throw new RuntimeException('RELATION_CONFIGURATION_INCOMPLETE');
        }

        return [
            'timezone' => $timezone,
            'payment_deadline_days' => (int) $deadlineDays,
            'payment_deadline_time' => $deadlineTime,
            'early_payment_period' => ['start' => (int) $period['start'], 'end' => (int) $period['end']],
            'bank' => [
                'name' => trim($bank['name']),
                'beneficiary' => trim($bank['beneficiary']),
                'agreement' => trim($bank['agreement']),
                'clabe' => $bank['clabe'],
            ],
            'configuration_versions' => $resolved
                ->mapWithKeys(fn (array $configuration, string $key): array => [$key => $configuration['version_id']])
                ->all(),
        ];
    }

    private function periodoValido(mixed $period): bool
    {
        return is_array($period)
            && filter_var($period['start'] ?? null, FILTER_VALIDATE_INT) !== false
            && filter_var($period['end'] ?? null, FILTER_VALIDATE_INT) !== false
            && (int) $period['start'] >= 0
            && (int) $period['end'] > (int) $period['start'];
    }

    private function bancoValido(mixed $bank): bool
    {
        return is_array($bank)
            && collect(['name', 'beneficiary', 'agreement'])->every(
                fn (string $key): bool => is_string($bank[$key] ?? null) && filled(trim($bank[$key]))
            )
            && is_string($bank['clabe'] ?? null)
            && preg_match('/^\d{18}$/', $bank['clabe']) === 1;
    }

    /** @param list<string> $keys */
    private function resolverClaves(array $keys, CarbonImmutable $at)
    {
        try {
            return collect($keys)
                ->mapWithKeys(fn (string $key): array => [$key => $this->configuraciones->resolver($key, Carbon::instance($at))]);
        } catch (BusinessException $error) {
            throw new RuntimeException('RELATION_CONFIGURATION_INCOMPLETE', previous: $error);
        }
    }
}
