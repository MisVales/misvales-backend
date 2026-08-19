<?php

namespace Tests\Unit\Vale;

use App\Exceptions\BusinessException;
use App\Exceptions\ExcepcionVale;
use App\Services\ConfiguracionServicio;
use App\Services\Vale\ConfiguracionFinancieraVale;
use PHPUnit\Framework\TestCase;

final class ConfiguracionFinancieraValeTest extends TestCase
{
    public function test_resuelve_y_normaliza_las_condiciones_financieras_publicadas(): void
    {
        $servicio = $this->createMock(ConfiguracionServicio::class);
        $servicio->method('resolver')->willReturnCallback(fn (string $key): array => match ($key) {
            'LOAN_COMMISSION_PERCENTAGE' => $this->configuracion($key, 'PERCENTAGE', '0.1'),
            'INTEREST_RATE_PER_FORTNIGHT' => $this->configuracion($key, 'PERCENTAGE', '0.05'),
            'VOUCHER_INSURANCE_AMOUNT' => $this->configuracion($key, 'DECIMAL', '100'),
            'VOUCHER_FORTNIGHTS_COUNT' => $this->configuracion($key, 'INTEGER', 8),
        });

        $resultado = (new ConfiguracionFinancieraVale($servicio))->resolver();

        self::assertSame([
            'loan_commission_percentage' => '0.100000',
            'simple_interest_percentage' => '0.050000',
            'insurance_amount' => '100.0000',
            'fortnights_count' => 8,
        ], $resultado['values']);
        self::assertSame(1, $resultado['versions']['LOAN_COMMISSION_PERCENTAGE']['version']);
    }

    public function test_rechaza_la_emision_cuando_falta_publicar_una_condicion(): void
    {
        $servicio = $this->createMock(ConfiguracionServicio::class);
        $servicio->method('resolver')->willReturnCallback(function (string $key): array {
            if ($key === 'VOUCHER_INSURANCE_AMOUNT') {
                throw new BusinessException('CONFIGURATION_NOT_FOUND', 'No existe configuración.', 404);
            }

            return match ($key) {
                'LOAN_COMMISSION_PERCENTAGE' => $this->configuracion($key, 'PERCENTAGE', '0.1'),
                'INTEREST_RATE_PER_FORTNIGHT' => $this->configuracion($key, 'PERCENTAGE', '0.05'),
                'VOUCHER_FORTNIGHTS_COUNT' => $this->configuracion($key, 'INTEGER', 8),
            };
        });

        try {
            (new ConfiguracionFinancieraVale($servicio))->resolver();
            self::fail('Se esperaba una excepción de configuración faltante.');
        } catch (ExcepcionVale $exception) {
            self::assertSame('VOUCHER_FINANCIAL_CONFIGURATION_MISSING', $exception->errorCode);
            self::assertSame(['seguro del vale'], $exception->details['missing']);
        }
    }

    /** @return array{version_id: string, version: int, type: string, value: string|int} */
    private function configuracion(string $key, string $type, string|int $value): array
    {
        return [
            'version_id' => $key.'-version',
            'version' => 1,
            'type' => $type,
            'value' => $value,
        ];
    }
}
