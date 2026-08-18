<?php

namespace App\Services\Credito;

use App\Models\ConfigurationDefinition;
use App\Models\LineaCredito;
use App\Models\SolicitudIncrementoLinea;
use Illuminate\Support\Facades\DB;

class ServicioSolicitudIncremento
{
    protected CalculadorSaldoCredito $calculador;

    public function __construct(CalculadorSaldoCredito $calculador)
    {
        $this->calculador = $calculador;
    }

    public function solicitar(LineaCredito $linea, string $montoSolicitado, ?string $notas = null): SolicitudIncrementoLinea
    {
        return DB::transaction(function () use ($linea, $montoSolicitado, $notas) {
            // Verificar si hay una solicitud pendiente
            $pendiente = $linea->solicitudesIncremento()
                ->whereIn('status', ['PENDING', 'PRE_AUTHORIZED'])
                ->exists();

            if ($pendiente) {
                throw new \Exception('Ya existe una solicitud de incremento en proceso.');
            }

            // Validar Regla de Integración: Obtener tolerancia desde DB (no hardcoded)
            $configDef = ConfigurationDefinition::where('key', 'CREDIT_TOLERANCE_AMOUNT')->first();
            if (! $configDef) {
                throw new \Exception('Falta configuración global CREDIT_TOLERANCE_AMOUNT.');
            }

            $activeVersion = $configDef->versions()
                ->where('status', 'PUBLISHED')
                ->whereNull('effective_to')
                ->first();

            $tolerancia = $activeVersion ? (string) json_decode($activeVersion->value) : '0.00';

            // Verificar si el saldo disponible es menor o igual a la tolerancia
            $saldos = $this->calculador->calcular($linea);
            $saldoDisponible = $saldos['saldo_disponible'];

            if (bccomp($saldoDisponible, $tolerancia, 2) > 0) {
                throw new \Exception("El saldo disponible actual ({$saldoDisponible}) supera la tolerancia permitida ({$tolerancia}) para solicitar incrementos.");
            }

            return SolicitudIncrementoLinea::create([
                'credit_line_id' => $linea->id,
                'distributor_id' => $linea->distributor_id,
                'requested_amount' => $montoSolicitado,
                'status' => 'PENDING',
                'distributor_notes' => $notas,
            ]);
        });
    }
}
