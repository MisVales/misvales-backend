<?php

namespace Tests\Unit\Configuracion;

use App\Http\Requests\Configuracion\ValidatesConfigurationValues;
use PHPUnit\Framework\TestCase;

final class ValidatesConfigurationValuesTest extends TestCase
{
    public function test_horarios_de_verificacion_aceptan_horas_y_minutos(): void
    {
        $rules = new class
        {
            use ValidatesConfigurationValues;

            public function for(string $key): array
            {
                return $this->getValueRulesForType('TIME', $key);
            }
        };

        foreach ([
            'VERIFICATION_START_TIME' => '03:00',
            'VERIFICATION_MAX_START_TIME' => '23:00',
        ] as $key => $value) {
            $valueRules = $rules->for($key)['value'];

            self::assertContains('date_format:H:i', $valueRules);
            self::assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $value);
        }
    }
}
