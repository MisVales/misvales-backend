<?php

namespace App\Services\Cliente;

use App\Exceptions\ExcepcionCliente;
use App\Models\Cliente;
use App\Models\MovimientoCarteraCliente;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ServicioCarteraInformativa
{
    private const INCREMENTOS = ['DEBT', 'ADJUSTMENT_INCREASE'];

    private const REDUCCIONES = ['PAYMENT', 'PARTIAL_PAYMENT', 'ADJUSTMENT_DECREASE'];

    public function __construct(private readonly AuditorCliente $auditor) {}

    public function registrar(Cliente $cliente, array $datos, User $actor): MovimientoCarteraCliente
    {
        return DB::transaction(function () use ($cliente, $datos, $actor): MovimientoCarteraCliente {
            $cliente = Cliente::query()->lockForUpdate()->findOrFail($cliente->id);
            $asignacion = $this->asignacionResponsable($cliente, $actor);
            $saldo = $this->saldoActual($cliente->id);
            $importe = isset($datos['amount']) ? $this->normalizarImporte($datos['amount']) : null;

            if (in_array($datos['entry_type'], self::REDUCCIONES, true)
                && bccomp($importe ?? '0.0000', $saldo, 4) === 1) {
                throw new ExcepcionCliente(
                    'CLIENT_PORTFOLIO_BALANCE_NEGATIVE',
                    'El movimiento dejaría el saldo informativo en negativo.',
                    409,
                    ['amount' => ['El importe excede el saldo informativo disponible.']],
                );
            }

            $ultimoPago = $datos['last_payment_at'] ?? null;
            if ($ultimoPago === null && in_array($datos['entry_type'], ['PAYMENT', 'PARTIAL_PAYMENT'], true)) {
                $ultimoPago = $datos['occurred_at'];
            }

            $movimiento = MovimientoCarteraCliente::create([
                'client_id' => $cliente->id,
                'distributor_id' => $asignacion->distributor_id,
                'entry_type' => $datos['entry_type'],
                'amount' => $importe,
                'informational_status' => $datos['informational_status'] ?? null,
                'occurred_at' => $datos['occurred_at'],
                'due_date' => $datos['due_date'] ?? null,
                'last_payment_at' => $ultimoPago,
                'note' => $datos['note'] ?? null,
                'related_voucher_id' => $datos['related_voucher_id'] ?? null,
                'recorded_by' => $actor->id,
            ]);

            OutboxEvent::create([
                'event_type' => 'CLIENT_PORTFOLIO_ENTRY_CREATED',
                'payload' => ['client_id' => $cliente->id, 'portfolio_entry_id' => $movimiento->id, 'distributor_id' => $asignacion->distributor_id],
                'status' => 'PENDING',
            ]);
            $this->auditor->registrar(
                'CLIENT_PORTFOLIO_ENTRY_CREATED', $cliente->id, $actor, $asignacion->branch_id,
                $asignacion->distributor_id,
                nuevos: ['entry_type' => $movimiento->entry_type, 'amount' => $importe, 'portfolio_entry_id' => $movimiento->id],
            );

            return $movimiento;
        }, 3);
    }

    public function actualizar(Cliente $cliente, MovimientoCarteraCliente $movimiento, array $datos, User $actor): MovimientoCarteraCliente
    {
        return DB::transaction(function () use ($cliente, $movimiento, $datos, $actor): MovimientoCarteraCliente {
            $cliente = Cliente::query()->lockForUpdate()->findOrFail($cliente->id);
            $asignacion = $this->asignacionResponsable($cliente, $actor);
            $movimiento = MovimientoCarteraCliente::query()->lockForUpdate()->findOrFail($movimiento->id);

            if ($movimiento->client_id !== $cliente->id || $movimiento->distributor_id !== $asignacion->distributor_id) {
                throw new ExcepcionCliente('CLIENT_PORTFOLIO_ENTRY_INVALID', 'El movimiento no pertenece al cliente indicado.', 404);
            }
            if ($movimiento->lock_version !== (int) $datos['lock_version']) {
                throw new ExcepcionCliente('RESOURCE_VERSION_CONFLICT', 'El movimiento fue modificado por otra operación.', 409);
            }

            $anteriores = $movimiento->only(['informational_status', 'occurred_at', 'due_date', 'last_payment_at', 'note']);
            $movimiento->fill(collect($datos)->only(['informational_status', 'occurred_at', 'due_date', 'last_payment_at', 'note'])->all());
            $movimiento->forceFill(['lock_version' => $movimiento->lock_version + 1])->save();

            OutboxEvent::create([
                'event_type' => 'CLIENT_PORTFOLIO_ENTRY_UPDATED',
                'payload' => ['client_id' => $cliente->id, 'portfolio_entry_id' => $movimiento->id, 'distributor_id' => $asignacion->distributor_id],
                'status' => 'PENDING',
            ]);
            $this->auditor->registrar(
                'CLIENT_PORTFOLIO_ENTRY_UPDATED', $cliente->id, $actor, $asignacion->branch_id,
                $asignacion->distributor_id, nuevos: array_merge(
                    ['portfolio_entry_id' => $movimiento->id, 'lock_version' => $movimiento->lock_version],
                    $movimiento->only(['informational_status', 'occurred_at', 'due_date', 'last_payment_at', 'note']),
                ),
                anteriores: $anteriores,
            );

            return $movimiento;
        }, 3);
    }

    public function resumen(string $clienteId): array
    {
        $saldo = $this->saldoActual($clienteId);
        $conteo = MovimientoCarteraCliente::query()->where('client_id', $clienteId)->count();
        $reducciones = $this->totalPorTipos($clienteId, self::REDUCCIONES);
        $ultimoPago = MovimientoCarteraCliente::query()->where('client_id', $clienteId)->max('last_payment_at');
        $vencidos = bccomp($saldo, '0.0000', 4) > 0
            && MovimientoCarteraCliente::query()
                ->where('client_id', $clienteId)->whereIn('entry_type', self::INCREMENTOS)
                ->whereDate('due_date', '<', today())->exists();

        $estado = match (true) {
            $conteo === 0 => null,
            bccomp($saldo, '0.0000', 4) === 0 => 'PAID',
            bccomp($reducciones, '0.0000', 4) > 0 => 'PARTIALLY_PAID',
            default => 'PENDING',
        };

        return [
            'current_balance' => $saldo,
            'informational_status' => $estado,
            'last_payment_at' => $ultimoPago,
            'entries_count' => $conteo,
            'has_overdue_entries' => $vencidos,
            'is_transfer_balance_zero' => bccomp($saldo, '0.0000', 4) === 0,
        ];
    }

    private function asignacionResponsable(Cliente $cliente, User $actor)
    {
        $asignacion = $cliente->asignacionVigente()->with('distribuidora')->lockForUpdate()->first();
        if ($asignacion === null || $asignacion->distribuidora?->user_id !== $actor->id || $asignacion->distribuidora?->status?->value !== 'ACTIVE') {
            throw new ExcepcionCliente('CLIENT_ASSIGNMENT_NOT_ACTIVE', 'La asignación del cliente no está activa.', 409);
        }

        return $asignacion;
    }

    private function saldoActual(string $clienteId): string
    {
        $incrementos = $this->totalPorTipos($clienteId, self::INCREMENTOS);
        $reducciones = $this->totalPorTipos($clienteId, self::REDUCCIONES);

        return bccomp($incrementos, $reducciones, 4) > 0 ? bcsub($incrementos, $reducciones, 4) : '0.0000';
    }

    private function totalPorTipos(string $clienteId, array $tipos): string
    {
        $total = MovimientoCarteraCliente::query()->where('client_id', $clienteId)->whereIn('entry_type', $tipos)->sum('amount');

        return $this->normalizarImporte((string) $total);
    }

    private function normalizarImporte(string $importe): string
    {
        [$entero, $decimales] = array_pad(explode('.', trim($importe), 2), 2, '');
        $entero = ltrim($entero, '0');

        return ($entero === '' ? '0' : $entero).'.'.str_pad(substr($decimales, 0, 4), 4, '0');
    }
}
