<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Reportes\ServicioExportacionExcel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ReportesExportController extends Controller
{
    public function __construct(private readonly ServicioExportacionExcel $exporter) {}

    public function pointsBalance(Request $request): BinaryFileResponse
    {
        $filters = $request->validate([
            'cutoff_at' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'uuid'],
        ]);

        $filePath = $this->exporter->saldoPuntos($request->user(), $filters);
        $fileName = 'saldo_puntos_distribuidoras' . (!empty($filters['cutoff_at']) ? '_corte_' . $filters['cutoff_at'] : '') . '.xlsx';

        return response()->download($filePath, $fileName)->deleteFileAfterSend(true);
    }

    public function preRequests(Request $request): BinaryFileResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:TODOS,PENDIENTES,VALIDADAS'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'uuid'],
        ]);

        if (isset($filters['status']) && $filters['status'] === 'TODOS') {
            unset($filters['status']);
        }

        $filePath = $this->exporter->presolicitudes($request->user(), $filters);
        $fileName = 'presolicitudes_pendientes_validadas.xlsx';

        return response()->download($filePath, $fileName)->deleteFileAfterSend(true);
    }
}
