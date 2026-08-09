<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ConfigurationDefinition;
use App\Services\ConfiguracionServicio;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ConfigurationSeeder extends Seeder
{
    public function run(ConfiguracionServicio $servicio): void
    {
        $admin = User::orderBy('created_at')->first();
        if (!$admin) {
            $admin = User::factory()->create([
                'email' => 'system_seeder_'.uniqid().'@misvales.com',
                'state' => 'ACTIVE'
            ]);
        }

        $configs = [
            [
                'key' => 'CUT_DAY_OF_MONTH',
                'name' => 'Día de corte del mes',
                'value_type' => 'INTEGER',
                'value' => 25,
            ],
            [
                'key' => 'PAYMENT_DAYS_AFTER_CUT',
                'name' => 'Días para pago después del corte',
                'value_type' => 'INTEGER',
                'value' => 20,
            ],
            [
                'key' => 'BUSINESS_TIMEZONE',
                'name' => 'Zona horaria de negocio',
                'value_type' => 'TIMEZONE',
                'value' => 'America/Monterrey',
            ],
            [
                'key' => 'CUT_TIME',
                'name' => 'Hora de ejecución de corte',
                'value_type' => 'TIME',
                'value' => '00:05',
            ],
            [
                'key' => 'PAYMENT_DEADLINE_TIME',
                'name' => 'Hora límite de pago',
                'value_type' => 'TIME',
                'value' => '23:59:59',
            ],
            [
                'key' => 'BANK_UPLOAD_DEADLINE_TIME',
                'name' => 'Hora límite para subida bancaria',
                'value_type' => 'TIME',
                'value' => '08:00',
            ],
            [
                'key' => 'POST_DUE_EVALUATION_TIME',
                'name' => 'Hora de evaluación de mora',
                'value_type' => 'TIME',
                'value' => '08:30',
            ],
            [
                'key' => 'CREDIT_TOLERANCE_AMOUNT',
                'name' => 'Monto de tolerancia de crédito',
                'value_type' => 'DECIMAL',
                'value' => 500.0000,
            ],
            [
                'key' => 'LATE_FEE_AMOUNT',
                'name' => 'Monto por comisión de mora',
                'value_type' => 'DECIMAL',
                'value' => 300.0000,
            ],
            [
                'key' => 'POINTS_DIVISOR_AMOUNT',
                'name' => 'Divisor para cálculo de puntos',
                'value_type' => 'DECIMAL',
                'value' => 1200.0000,
            ],
            [
                'key' => 'POINTS_MULTIPLIER',
                'name' => 'Multiplicador de puntos',
                'value_type' => 'INTEGER',
                'value' => 3,
            ],
            [
                'key' => 'POINT_VALUE_AMOUNT',
                'name' => 'Valor monetario por punto',
                'value_type' => 'DECIMAL',
                'value' => 2.0000,
            ],
            [
                'key' => 'LATE_POINTS_REDUCTION_RATE',
                'name' => 'Tasa de reducción de puntos por mora',
                'value_type' => 'PERCENTAGE',
                'value' => 0.2000,
            ],
            [
                'key' => 'MODIFICATION_TOKEN_TTL',
                'name' => 'TTL del token de modificación',
                'value_type' => 'DURATION',
                'unit' => 'minutos',
                'value' => 5,
            ],
        ];

        foreach ($configs as $config) {
            $exists = ConfigurationDefinition::where('key', $config['key'])->first();
            
            if (!$exists) {
                // Inicializa effective_from a la fecha actual para ser válidos inmediatamente
                $config['reason'] = 'Valores iniciales del sistema';
                $config['effective_from'] = now('America/Monterrey')->format('Y-m-d H:i:s');
                
                DB::transaction(function () use ($config, $admin, $servicio) {
                    $definition = $servicio->crearConfiguracion($config, $admin->id);
                    $version = $definition->versions()->first();
                    $servicio->publicarVersion($version, $admin->id);
                });
                
                $this->command->info("Configuración {$config['key']} creada y publicada.");
            } else {
                $this->command->info("Configuración {$config['key']} ya existe. Omitiendo (Idempotencia).");
            }
        }
    }
}
