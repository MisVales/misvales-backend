<?php

namespace App\Services\Vale;

use App\Exceptions\BusinessException;
use App\Exceptions\ExcepcionVale;
use App\Services\ConfiguracionServicio;

final class ConfiguracionFinancieraVale
{
    /** @var array<string, array{key: string, type: string, label: string}> */
    private const CAMPOS = [
        'loan_commission_percentage' => [
            'key' => 'LOAN_COMMISSION_PERCENTAGE',
            'type' => 'PERCENTAGE',
            'label' => 'comisión del préstamo',
        ],
        'simple_interest_percentage' => [
            'key' => 'INTEREST_RATE_PER_FORTNIGHT',
            'type' => 'PERCENTAGE',
            'label' => 'interés por quincena',
        ],
        'insurance_amount' => [
            'key' => 'VOUCHER_INSURANCE_AMOUNT',
            'type' => 'DECIMAL',
            'label' => 'seguro del vale',
        ],
        'minimum_installment_count' => [
            'key' => 'VOUCHER_MIN_FORTNIGHTS_COUNT',
            'type' => 'INTEGER',
            'label' => 'mínimo de quincenas por vale',
        ],
        'maximum_installment_count' => [
            'key' => 'VOUCHER_MAX_FORTNIGHTS_COUNT',
            'type' => 'INTEGER',
            'label' => 'máximo de quincenas por vale',
        ],
        'late_fee_amount' => [
            'key' => 'LATE_FEE_AMOUNT',
            'type' => 'DECIMAL',
            'label' => 'recargo por falta de pago',
        ],
    ];

    public function __construct(private readonly ConfiguracionServicio $configuraciones) {}

    /**
     * @return array{
     *     values: array{loan_commission_percentage: string, simple_interest_percentage: string, insurance_amount: string, minimum_installment_count: int, maximum_installment_count: int, late_fee_amount: string},
     *     versions: array<string, array{version_id: string, version: int, value: mixed}>
     * }
     */
    public function resolver(): array
    {
        $values = [];
        $versions = [];
        $faltantes = [];

        foreach (self::CAMPOS as $campo => $definicion) {
            try {
                $configuracion = $this->configuraciones->resolver($definicion['key']);
            } catch (BusinessException $exception) {
                if ($exception->errorCode !== 'CONFIGURATION_NOT_FOUND') {
                    throw $exception;
                }

                $faltantes[] = $definicion['label'];

                continue;
            }

            if (($configuracion['type'] ?? null) !== $definicion['type']) {
                throw new ExcepcionVale(
                    'VOUCHER_FINANCIAL_CONFIGURATION_INVALID',
                    'Una condición financiera vigente tiene un tipo de valor inválido.',
                    409,
                );
            }

            try {
                $values[$campo] = $this->normalizarValor($campo, $configuracion['value']);
            } catch (\InvalidArgumentException) {
                throw new ExcepcionVale(
                    'VOUCHER_FINANCIAL_CONFIGURATION_INVALID',
                    'Una condición financiera vigente tiene un valor inválido.',
                    409,
                );
            }

            $versions[$definicion['key']] = [
                'version_id' => (string) $configuracion['version_id'],
                'version' => (int) $configuracion['version'],
                'value' => $configuracion['value'],
            ];
        }

        if ($faltantes !== []) {
            throw new ExcepcionVale(
                'VOUCHER_FINANCIAL_CONFIGURATION_MISSING',
                'Aún no se pueden otorgar vales: falta publicar la '.implode(', ', $faltantes).'.',
                409,
                ['missing' => array_values($faltantes)],
            );
        }

        if ($values['minimum_installment_count'] > $values['maximum_installment_count']) {
            throw new ExcepcionVale(
                'VOUCHER_FINANCIAL_CONFIGURATION_INVALID',
                'El mínimo de quincenas no puede ser mayor que el máximo.',
                409,
            );
        }

        /** @var array{loan_commission_percentage: string, simple_interest_percentage: string, insurance_amount: string, minimum_installment_count: int, maximum_installment_count: int, late_fee_amount: string} $values */
        return ['values' => $values, 'versions' => $versions];
    }

    private function normalizarValor(string $campo, mixed $valor): string|int
    {
        $texto = is_scalar($valor) ? trim((string) $valor) : '';

        return match ($campo) {
            'loan_commission_percentage', 'simple_interest_percentage' => $this->porcentaje($texto),
            'insurance_amount', 'late_fee_amount' => $this->monto($texto),
            'minimum_installment_count', 'maximum_installment_count' => $this->enteroPositivo($texto),
            default => throw new \InvalidArgumentException('Campo financiero no reconocido.'),
        };
    }

    private function porcentaje(string $valor): string
    {
        if (! is_numeric($valor) || bccomp($valor, '0', 6) < 0 || bccomp($valor, '1', 6) > 0) {
            throw new \InvalidArgumentException('Porcentaje inválido.');
        }

        return bcadd($valor, '0', 6);
    }

    private function monto(string $valor): string
    {
        if (! is_numeric($valor) || bccomp($valor, '0', 4) < 0) {
            throw new \InvalidArgumentException('Monto inválido.');
        }

        return bcadd($valor, '0', 4);
    }

    private function enteroPositivo(string $valor): int
    {
        if (filter_var($valor, FILTER_VALIDATE_INT) === false || (int) $valor < 1) {
            throw new \InvalidArgumentException('Número de quincenas inválido.');
        }

        return (int) $valor;
    }
}
