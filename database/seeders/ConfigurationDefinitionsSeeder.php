<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ConfigurationDefinition;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ConfigurationDefinitionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $managerId = $this->managerId();

            foreach (self::definitions() as $data) {
                $definition = ConfigurationDefinition::query()->firstOrNew(['key' => $data['key']]);
                $definition->fill($data);
                $definition->is_required = true;
                $definition->is_sensitive = false;
                $definition->status = 'ACTIVE';

                if (! $definition->exists) {
                    $definition->lock_version = 0;
                    $definition->created_by = $managerId;
                } else {
                    $definition->updated_by = $managerId;
                }

                $definition->save();
            }

            ConfigurationDefinition::query()
                ->whereIn('key', ['VOUCHER_FORTNIGHTS_COUNT', 'MODIFICATION_TOKEN_TTL', 'EARLY_PAYMENT_PERIOD'])
                ->each(function (ConfigurationDefinition $definition) use ($managerId): void {
                    $definition->forceFill([
                        'status' => 'INACTIVE',
                        'updated_by' => $managerId,
                    ])->save();
                });
        });
    }

    /** @return list<array{key: string, name: string, description: string, value_type: string, unit: ?string}> */
    public static function definitions(): array
    {
        return [
            ['key' => 'CUT_DAY_OF_MONTH', 'name' => 'Día global de corte', 'description' => 'Día del mes en que se ejecuta el corte global.', 'value_type' => 'INTEGER', 'unit' => 'day_of_month'],
            ['key' => 'PAYMENT_DAYS_AFTER_CUT', 'name' => 'Días posteriores al corte', 'description' => 'Número de días posteriores al corte para el vencimiento del pago.', 'value_type' => 'INTEGER', 'unit' => 'days'],
            ['key' => 'BUSINESS_TIMEZONE', 'name' => 'Zona horaria operativa', 'description' => 'Zona horaria oficial para los procesos de negocio.', 'value_type' => 'TIMEZONE', 'unit' => null],
            ['key' => 'CUT_TIME', 'name' => 'Hora de ejecución del corte', 'description' => 'Hora local en que inicia el proceso de corte.', 'value_type' => 'TIME', 'unit' => null],
            ['key' => 'PAYMENT_DEADLINE_TIME', 'name' => 'Hora límite de pago', 'description' => 'Hora local límite para considerar un pago oportuno.', 'value_type' => 'TIME', 'unit' => null],
            ['key' => 'BANK_UPLOAD_DEADLINE_TIME', 'name' => 'Hora límite de carga bancaria', 'description' => 'Hora local límite para cargar el archivo bancario.', 'value_type' => 'TIME', 'unit' => null],
            ['key' => 'POST_DUE_EVALUATION_TIME', 'name' => 'Hora de evaluación posterior al vencimiento', 'description' => 'Hora local para evaluar obligaciones vencidas.', 'value_type' => 'TIME', 'unit' => null],
            ['key' => 'VERIFICATION_START_TIME', 'name' => 'Hora inicial de verificaciones', 'description' => 'Primera hora global en que puede iniciar una visita de verificación.', 'value_type' => 'TIME', 'unit' => null],
            ['key' => 'VERIFICATION_MAX_START_TIME', 'name' => 'Hora máxima de inicio de verificaciones', 'description' => 'Última hora global permitida para iniciar una visita de verificación.', 'value_type' => 'TIME', 'unit' => null],
            ['key' => 'CREDIT_TOLERANCE_AMOUNT', 'name' => 'Tolerancia de la regla del 50 %', 'description' => 'Tolerancia monetaria aplicada a la restricción inicial de uso de crédito.', 'value_type' => 'DECIMAL', 'unit' => 'MXN'],
            ['key' => 'LOAN_COMMISSION_PERCENTAGE', 'name' => 'Comisión del préstamo', 'description' => 'Porcentaje global aplicado al capital al emitir un vale nuevo.', 'value_type' => 'PERCENTAGE', 'unit' => 'percentage'],
            ['key' => 'INTEREST_RATE_PER_FORTNIGHT', 'name' => 'Interés por quincena', 'description' => 'Porcentaje global de interés simple aplicado por cada quincena de un vale nuevo.', 'value_type' => 'PERCENTAGE', 'unit' => 'percentage'],
            ['key' => 'VOUCHER_INSURANCE_AMOUNT', 'name' => 'Seguro del vale', 'description' => 'Importe global de seguro aplicado al emitir un vale nuevo.', 'value_type' => 'DECIMAL', 'unit' => 'MXN'],
            ['key' => 'VOUCHER_MIN_FORTNIGHTS_COUNT', 'name' => 'Mínimo de quincenas por vale', 'description' => 'Número mínimo de quincenas que puede elegir una distribuidora al otorgar un vale.', 'value_type' => 'INTEGER', 'unit' => 'fortnights'],
            ['key' => 'VOUCHER_MAX_FORTNIGHTS_COUNT', 'name' => 'Máximo de quincenas por vale', 'description' => 'Número máximo de quincenas que puede elegir una distribuidora al otorgar un vale.', 'value_type' => 'INTEGER', 'unit' => 'fortnights'],
            ['key' => 'LATE_FEE_AMOUNT', 'name' => 'Recargo por falta de pago', 'description' => 'Importe del recargo aplicable por falta de pago.', 'value_type' => 'DECIMAL', 'unit' => 'MXN'],
            ['key' => 'POINTS_DIVISOR_AMOUNT', 'name' => 'Divisor para generar puntos', 'description' => 'Capital liquidado anticipadamente requerido para calcular cada bloque de puntos.', 'value_type' => 'DECIMAL', 'unit' => 'MXN'],
            ['key' => 'POINTS_MULTIPLIER', 'name' => 'Multiplicador de puntos', 'description' => 'Puntos acreditados por cada bloque completo de capital en una liquidación anticipada.', 'value_type' => 'INTEGER', 'unit' => 'points'],
            ['key' => 'POINT_VALUE_AMOUNT', 'name' => 'Valor monetario del punto', 'description' => 'Valor vigente en pesos de cada punto al solicitar un canje.', 'value_type' => 'DECIMAL', 'unit' => 'MXN'],
            ['key' => 'RELATION_PAYMENT_BANK', 'name' => 'Datos bancarios para relaciones', 'description' => 'Banco, beneficiario, convenio y CLABE publicados para el pago de relaciones.', 'value_type' => 'JSON', 'unit' => null],
        ];
    }

    private function managerId(): string
    {
        return User::query()
            ->whereHas('roleScopes', fn ($query) => $query
                ->where('scope_type', 'GLOBAL')
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->whereHas('role', fn ($roleQuery) => $roleQuery->where('code', 'general_manager')))
            ->oldest('created_at')
            ->value('id') ?? throw new RuntimeException('No existe un gerente general para atribuir las configuraciones iniciales.');
    }
}
