<?php

namespace App\Services\Relacion;

use App\Models\Distribuidora;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

final class ServicioPdfEstadoCuenta
{
    public function generar(Distribuidora $distributor): string
    {
        $distributor->loadMissing(['usuario', 'sucursal', 'lineaCredito']);
        $relations = $distributor->relaciones()
            ->with(['partidas', 'pagos.asignaciones'])
            ->oldest('cutoff_at')
            ->get();
        $logoPath = storage_path('app/public/branding/misvales.jpg');
        if (! is_file($logoPath)) {
            throw new RuntimeException('ACCOUNT_STATEMENT_PDF_LOGO_MISSING');
        }

        $relations->each(function ($relation): void {
            $paidByItem = $relation->pagos->flatMap->asignaciones
                ->groupBy('relation_item_id')
                ->map(fn ($allocations): string => $allocations->reduce(
                    fn (string $sum, $allocation): string => bcadd($sum, (string) $allocation->amount, 4),
                    '0.0000',
                ));
            $relation->setAttribute('statement_items', $relation->partidas->map(function ($item) use ($paidByItem): array {
                $snapshot = $item->snapshot;
                $charge = (string) ($snapshot['misvales_payment'] ?? $item->misvales_amount);
                $paid = (string) ($paidByItem->get($item->id) ?? '0.0000');

                return [
                    'folio' => (string) ($snapshot['folio'] ?? 'Sin folio'),
                    'client' => (string) ($snapshot['client'] ?? 'Sin cliente'),
                    'installment' => ($snapshot['installment'] ?? '?').'/'.($snapshot['total_installments'] ?? '?'),
                    'charge' => $charge,
                    'paid' => $paid,
                    'pending' => bccomp($charge, $paid, 4) > 0 ? bcsub($charge, $paid, 4) : '0.0000',
                    'status' => bccomp($paid, '0', 4) === 0 ? 'No pagada' : (bccomp($paid, $charge, 4) < 0 ? 'Abono' : 'Pagada'),
                ];
            }));
        });

        $currentBalance = (string) ($relations->last()?->balance ?? '0.0000');
        $authorized = (string) ($distributor->lineaCredito?->total_authorized ?? '0.0000');
        $ledgerUsed = (string) ($distributor->lineaCredito?->used_balance ?? '0.0000');
        $effectiveUsed = bccomp($currentBalance, $ledgerUsed, 4) > 0 ? $currentBalance : $ledgerUsed;

        $html = view('relations.account-statement', [
            'distributor' => $distributor,
            'relations' => $relations,
            'authorized' => $authorized,
            'used' => $effectiveUsed,
            'available' => bccomp($authorized, $effectiveUsed, 4) > 0 ? bcsub($authorized, $effectiveUsed, 4) : '0.0000',
            'currentBalance' => $currentBalance,
            'logo' => 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath)),
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
