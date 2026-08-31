<?php

namespace App\Services\Conciliacion;

use App\Exceptions\ExcepcionConciliacion;
use App\Models\PagoRelacion;
use App\Models\RelacionDistribuidora;
use App\Models\TransferenciaBancariaSimulada;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

final class ServicioTransferenciasBancariasSimuladas
{
    public function registrar(array $data, User $actor, string $branchId): TransferenciaBancariaSimulada
    {
        if (! in_array($data['payment_type'], ['TRANSFER', 'ONLINE_BANKING', 'COUNTER'], true)) {
            throw new ExcepcionConciliacion(
                'BANK_SIMULATION_TYPE_INVALID',
                'Solo se pueden simular transferencias y pagos por banca en línea.',
                422,
            );
        }

        if ($data['payment_type'] === 'COUNTER' && ! $actor->hasRole('cashier')) {
            throw new ExcepcionConciliacion('COUNTER_PAYMENT_ROLE_DENIED', 'Solo una cajera puede registrar pagos en ventanilla.', 403);
        }

        if ($data['payment_type'] !== 'COUNTER' && $actor->hasRole('cashier')) {
            throw new ExcepcionConciliacion('CASHIER_PAYMENT_TYPE_INVALID', 'Caja solo registra pagos recibidos en ventanilla.', 422);
        }

        $relation = RelacionDistribuidora::query()->whereKey($data['relation_id'])->firstOrFail();
        if ($relation->branch_id !== $branchId) {
            throw new ExcepcionConciliacion('BANK_SIMULATION_SCOPE_DENIED', 'La relación no pertenece a la sucursal autorizada.', 403);
        }

        $paidAt = isset($data['paid_at'])
            ? CarbonImmutable::parse($data['paid_at'], config('app.timezone'))
            : CarbonImmutable::now(config('app.timezone'));
        $concept = Str::squish((string) ($data['concept'] ?? ''));

        return TransferenciaBancariaSimulada::query()->create([
            'branch_id' => $branchId,
            'relation_id' => $relation->id,
            'created_by' => $actor->id,
            'concept' => $concept !== '' ? $concept : 'Abono a referencia '.$relation->payment_reference,
            'payment_reference' => $relation->payment_reference,
            'amount' => $data['amount'],
            'bank_folio' => 'SIM-'.$paidAt->format('YmdHis').'-'.Str::upper(Str::random(8)),
            'paid_at' => $paidAt,
            'payment_type' => $data['payment_type'],
        ]);
    }

    public function listar(?string $branchId, string $processRunId): Collection
    {
        $this->asegurarSaldosFavorAplicados($branchId, $processRunId);

        return $this->movimientosDelPeriodo($branchId, $processRunId)
            ->with('relation:id,payment_reference,process_run_id')
            ->latest('paid_at')
            ->limit(100)
            ->get();
    }

    public function exportar(?string $branchId, string $processRunId): string
    {
        $this->asegurarSaldosFavorAplicados($branchId, $processRunId);

        $transfers = $this->movimientosDelPeriodo($branchId, $processRunId)
            ->oldest('paid_at')
            ->get();

        return $this->crearArchivo($transfers);
    }

    public function crearArchivo(Collection $transfers): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bank_simulations_').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet();
        $sheet->setColumnWidth(8, 1);
        $sheet->setColumnWidth(34, 2);
        $sheet->setColumnWidth(20, 3);
        $sheet->setColumnWidth(14, 4);
        $sheet->setColumnWidth(22, 5);
        $sheet->setColumnWidth(16, 6);
        $sheet->setColumnWidth(12, 7);
        $sheet->setColumnWidth(22, 8);
        $headerStyle = new Style(fontBold: true, fontColor: Color::WHITE, backgroundColor: '0B6B3A');
        $moneyStyle = new Style(format: '$#,##0.00');
        $writer->addRow(Row::fromValuesWithStyle([
            'item',
            'Concepto',
            'Referencia',
            'Pago',
            'Folio de pago',
            'Fecha de pago',
            'Hora',
            'tipo de pago',
        ], $headerStyle, 24));

        foreach ($transfers->values() as $index => $transfer) {
            $writer->addRow(Row::fromValuesWithStyles([
                $index + 1,
                $transfer->concept,
                $transfer->payment_reference,
                (float) $transfer->amount,
                $transfer->bank_folio,
                $transfer->paid_at->format('d/m/Y'),
                $transfer->paid_at->format('H:i'),
                $this->paymentTypeLabel($transfer->payment_type),
            ], [3 => $moneyStyle]));
        }

        $writer->close();

        return $path;
    }

    private function paymentTypeLabel(string $type): string
    {
        return match ($type) {
            'ONLINE_BANKING' => 'Banca en línea',
            'COUNTER' => 'Pago en ventanilla',
            'CREDIT_BALANCE' => 'Saldo a favor',
            default => 'Transferencia',
        };
    }

    private function asegurarSaldosFavorAplicados(?string $branchId, string $processRunId): void
    {
        $relations = RelacionDistribuidora::query()
            ->with('distribuidora.usuario:id')
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->where('process_run_id', $processRunId)
            ->get();

        foreach ($relations as $relation) {
            $payment = PagoRelacion::query()
                ->where('relation_id', $relation->id)
                ->where('source_type', 'CREDIT_BALANCE')
                ->oldest('applied_at')
                ->first();
            if ($payment === null || $relation->distribuidora?->usuario === null) {
                continue;
            }

            TransferenciaBancariaSimulada::query()->firstOrCreate(
                ['bank_folio' => 'SALDO-FAVOR-'.$relation->id],
                [
                    // A global query spans multiple branches; persist the transfer
                    // in the relation's actual branch rather than a null scope.
                    'branch_id' => $relation->branch_id,
                    'relation_id' => $relation->id,
                    'created_by' => $relation->distribuidora->usuario->id,
                    'concept' => 'Aplicación de saldo a favor',
                    'payment_reference' => $relation->payment_reference,
                    'amount' => $payment->amount,
                    'paid_at' => $payment->applied_at,
                    'payment_type' => 'CREDIT_BALANCE',
                ],
            );
        }
    }

    /**
     * Un movimiento pertenece al periodo en que se realizó, no al periodo en que
     * nació la relación. Esto permite conciliar pagos de relaciones anteriores.
     */
    private function movimientosDelPeriodo(?string $branchId, string $processRunId): Builder
    {
        $run = DB::table('relation_process_runs')->where('id', $processRunId)->firstOrFail();
        $previousCutoff = DB::table('relation_process_runs')
            ->where('status', 'COMPLETED')
            ->where('cutoff_at', '<', $run->cutoff_at)
            ->latest('cutoff_at')
            ->value('cutoff_at');

        return TransferenciaBancariaSimulada::query()
            ->when($branchId !== null, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->where('paid_at', '<=', $run->cutoff_at)
            ->when($previousCutoff !== null, fn (Builder $query) => $query->where('paid_at', '>', $previousCutoff));
    }
}
