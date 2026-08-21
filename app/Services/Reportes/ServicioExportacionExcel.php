<?php

namespace App\Services\Reportes;

use App\Models\RelacionDistribuidora;
use App\Models\SolicitudDistribuidora;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;

final class ServicioExportacionExcel
{
    public function saldoPuntos(User $actor, array $filters): string
    {
        $query = RelacionDistribuidora::query()
            ->with(['distribuidora.usuario'])
            ->orderBy('cutoff_at', 'desc');

        $this->scopeRelations($query, $actor, $filters);

        if (!empty($filters['cutoff_at'])) {
            $query->whereDate('cutoff_at', $filters['cutoff_at']);
        }

        $relations = $query->get();

        $tempFile = tempnam(sys_get_temp_dir(), 'puntos_') . '.xlsx';
        $writer = new Writer();
        $writer->openToFile($tempFile);


        
        $writer->addRow(Row::fromValues([
            'Número de Distribuidora',
            'Nombre de Distribuidora',
            'Referencia de Corte',
            'Fecha del Corte',
            'Total Productos Otorgados (Cartera)',
            'Total a Pagar (MisVales)',
            'Puntos Generados',
            'Puntos Descontados (Atraso)',
            'Saldo de Puntos',
            'Valor Monetario ($)'
        ]));

        foreach ($relations as $rel) {
            $distNumber = $rel->distribuidora?->distributor_number ?? $rel->header_snapshot['number'] ?? '';
            $distName = $rel->distribuidora?->usuario?->name ?? $rel->header_snapshot['name'] ?? '';
            
            $cartera = (float) $rel->portfolio_total;
            $generados = floor($cartera / 1200) * 3;
            
            // Tiene pagos fuera de tiempo?
            $tieneAtraso = $rel->surcharge_total > 0 || $rel->pagos()->where('applied_at', '>', $rel->payment_deadline_at)->exists();
            
            $descontados = $tieneAtraso ? floor($generados * 0.2) : 0;
            $saldo = $generados - $descontados;
            $valorMonetario = $saldo * 2;

            $writer->addRow(Row::fromValues([
                $distNumber,
                $distName,
                $rel->payment_reference,
                $rel->cutoff_at?->format('Y-m-d H:i:s'),
                $cartera,
                (float) $rel->misvales_total,
                $generados,
                $descontados,
                $saldo,
                $valorMonetario
            ]));
        }

        $writer->close();
        return $tempFile;
    }

    public function presolicitudes(User $actor, array $filters): string
    {
        $query = SolicitudDistribuidora::query()
            ->with(['datosPersonales', 'sucursal', 'verificationVisits' => function($q){
                $q->latest('created_at')->with('verifier');
            }])
            ->orderBy('created_at', 'desc');

        $this->scopeApplications($query, $actor, $filters);

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'PENDIENTES') {
                $query->whereIn('status', ['DRAFT', 'COORDINATOR_REVIEW', 'COORDINATOR_CORRECTION']);
            } elseif ($filters['status'] === 'VALIDADAS') {
                $query->whereIn('status', ['VERIFIER_ASSIGNED', 'PHYSICAL_VERIFICATION', 'COORDINATOR_EVALUATION', 'MANAGER_AUTHORIZATION', 'AUTHORIZED_PENDING_ACTIVATION', 'ACTIVE']);
            } else {
                $query->where('status', $filters['status']);
            }
        }
        
        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $apps = $query->get();

        $tempFile = tempnam(sys_get_temp_dir(), 'presol_') . '.xlsx';
        $writer = new Writer();
        $writer->openToFile($tempFile);


        
        $writer->addRow(Row::fromValues([
            'No. Presolicitud',
            'Fecha de Creación',
            'Nombre Solicitante',
            'Sucursal',
            'Estado',
            'Verificador Asignado',
            'Fecha de Validación',
            'Observaciones'
        ]));

        foreach ($apps as $app) {
            $datos = $app->datosPersonales;
            $nombre = $datos ? trim($datos->first_name . ' ' . $datos->first_last_name . ' ' . $datos->second_last_name) : '';
            
            $visit = $app->verificationVisits->first();
            $verificador = $visit?->verifier?->name ?? '';
            $fechaVal = $visit?->completed_at?->format('Y-m-d H:i:s') ?? '';
            $obs = $visit?->observations ?? '';

            $writer->addRow(Row::fromValues([
                $app->application_number,
                $app->created_at?->format('Y-m-d H:i:s'),
                $nombre,
                $app->sucursal?->name ?? '',
                $app->status->value,
                $verificador,
                $fechaVal,
                $obs
            ]));
        }

        $writer->close();
        return $tempFile;
    }

    private function scopeRelations($query, User $actor, array $filters): void
    {
        abort_unless($actor->hasPermissionTo('reports.view_global') || $actor->hasPermissionTo('reports.view_branch'), 403);
        if (! $actor->hasPermissionTo('reports.view_global')) {
            $branches = $actor->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->whereNotNull('branch_id')->pluck('branch_id');
            $query->whereIn('branch_id', $branches);
        } elseif (!empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }
    }

    private function scopeApplications($query, User $actor, array $filters): void
    {
        abort_unless($actor->hasPermissionTo('reports.view_global') || $actor->hasPermissionTo('reports.view_branch'), 403);
        if (! $actor->hasPermissionTo('reports.view_global')) {
            $branches = $actor->roleScopes()->where('status', 'ACTIVE')->whereNull('revoked_at')->whereNotNull('branch_id')->pluck('branch_id');
            $query->whereIn('branch_id', $branches);
        } elseif (!empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }
    }
}
