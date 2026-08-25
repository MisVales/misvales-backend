<?php

namespace App\Services\Relacion;

use App\Models\RelacionDistribuidora;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

final class ServicioPdfRelacion
{
    public function generar(RelacionDistribuidora $relation): string
    {
        $relation->loadMissing('partidas');
        $logoPath = storage_path('app/public/branding/misvales.jpg');
        if (! is_file($logoPath)) {
            throw new RuntimeException('RELATION_PDF_LOGO_MISSING');
        }

        $rows = $relation->partidas->map(function ($item): array {
            $snapshot = $item->snapshot;
            $distributorProfit = (string) ($snapshot['distributor_profit'] ?? '0');
            $clientPayment = (string) ($snapshot['client_payment'] ?? $item->portfolio_amount);
            $surcharge = (string) ($snapshot['surcharge'] ?? '0');
            $netPayment = (string) ($snapshot['misvales_payment'] ?? $item->misvales_amount);

            return [
                'product' => (string) ($snapshot['product'] ?? '—'),
                'client' => (string) ($snapshot['client'] ?? '—'),
                'payments_made' => ($snapshot['installment'] ?? '—').'/'.($snapshot['total_installments'] ?? '—'),
                'distributor_profit' => $distributorProfit,
                'client_payment' => $clientPayment,
                'surcharge' => $surcharge,
                'net_payment' => bcadd($netPayment, $surcharge, 4),
            ];
        });

        $totals = [
            'distributor_profit' => $rows->reduce(fn (string $sum, array $row): string => bcadd($sum, $row['distributor_profit'], 4), '0.0000'),
            'client_payment' => $rows->reduce(fn (string $sum, array $row): string => bcadd($sum, $row['client_payment'], 4), '0.0000'),
            'surcharge' => $rows->reduce(fn (string $sum, array $row): string => bcadd($sum, $row['surcharge'], 4), '0.0000'),
            'net_payment' => $rows->reduce(fn (string $sum, array $row): string => bcadd($sum, $row['net_payment'], 4), '0.0000'),
        ];

        $html = view('relations.pdf', [
            'relation' => $relation,
            'rows' => $rows,
            'totals' => $totals,
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
