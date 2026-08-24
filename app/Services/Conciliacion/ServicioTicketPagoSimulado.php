<?php

namespace App\Services\Conciliacion;

use App\Exceptions\ExcepcionConciliacion;
use App\Models\TransferenciaBancariaSimulada;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

final class ServicioTicketPagoSimulado
{
    public function generar(TransferenciaBancariaSimulada $transfer): string
    {
        if ($transfer->payment_type !== 'COUNTER') {
            throw new ExcepcionConciliacion('COUNTER_TICKET_NOT_AVAILABLE', 'El ticket solo está disponible para pagos simulados en ventanilla.', 422);
        }

        $transfer->loadMissing('relation.distribuidora.usuario');
        $logoPath = storage_path('app/public/branding/misvales.jpg');
        if (! is_file($logoPath)) {
            throw new RuntimeException('COUNTER_TICKET_LOGO_MISSING');
        }

        $html = view('payments.simulated-counter-ticket', [
            'transfer' => $transfer,
            'relation' => $transfer->relation,
            'logo' => 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($logoPath)),
        ])->render();

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper([0, 0, 226.77, 481.89]);
        $dompdf->render();

        return $dompdf->output();
    }
}
