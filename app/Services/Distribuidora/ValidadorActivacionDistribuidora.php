<?php

namespace App\Services\Distribuidora;

use App\Enums\ApplicationAuthorizationDecision;
use App\Enums\ApplicationEvaluationResult;
use App\Enums\ApplicationStatus;
use App\Enums\BaseStatus;
use App\Enums\VerificationVisitResult;
use App\Enums\VerificationVisitStatus;
use App\Enums\VersionStatus;
use App\Exceptions\ExcepcionDistribuidora;
use App\Models\ApplicationAuthorization;
use App\Models\ApplicationEvaluation;
use App\Models\Branch;
use App\Models\CategoryVersion;
use App\Models\Distribuidora;
use App\Models\DistributorApplication;
use App\Models\User;
use App\Models\VerificationVisit;
use Carbon\CarbonInterface;

class ValidadorActivacionDistribuidora
{
    public function validarComponentesObligatorios(Distribuidora $distribuidora): void
    {
        $distribuidora->load([
            'solicitud.autorizacion',
            'coordinadorVigente',
            'categoriaVigente.versionCategoria.category',
            'lineaCredito.movimientos',
            'lineaCredito.restricciones',
        ]);

        $autorizacion = $distribuidora->solicitud?->autorizacion;
        $linea = $distribuidora->lineaCredito;
        $importe = $autorizacion?->initial_credit_line_amount;
        $coordinadorValido = $distribuidora->coordinadorVigente?->branch_id === $distribuidora->branch_id;
        $categoriaValida = $distribuidora->categoriaVigente !== null;
        $lineaValida = $linea !== null
            && $importe !== null
            && bccomp($linea->total_authorized, $importe, 4) === 0
            && $linea->movimientos->contains(fn ($movimiento) => $movimiento->type->value === 'INITIAL_AUTHORIZATION'
                && bccomp($movimiento->amount, $importe, 4) === 0
                && $movimiento->source_id === $autorizacion->id)
            && $linea->restricciones->contains(fn ($restriccion) => $restriccion->type === 'INITIAL_50_PERCENT'
                && $restriccion->status->value === 'ACTIVE'
                && bccomp($restriccion->base_total, $importe, 4) === 0);

        if ($autorizacion?->decision !== ApplicationAuthorizationDecision::APPROVED
            || $importe === null
            || bccomp($importe, '0.0000', 4) <= 0
            || ! $coordinadorValido
            || ! $categoriaValida
            || ! $lineaValida) {
            throw new ExcepcionDistribuidora(
                'DISTRIBUTOR_ACTIVATION_INCOMPLETE',
                'La distribuidora no cuenta con todos los componentes obligatorios para activarse.',
                409,
            );
        }
    }

    public function validarVerificacion(?VerificationVisit $visita, ?ApplicationEvaluation $evaluacion): void
    {
        if ($visita === null
            || $visita->status !== VerificationVisitStatus::COMPLETED
            || $visita->result !== VerificationVisitResult::FAVORABLE
            || $evaluacion === null
            || $evaluacion->verification_visit_id !== $visita->id
            || $evaluacion->result !== ApplicationEvaluationResult::COMPLIES) {
            throw new ExcepcionDistribuidora(
                'DISTRIBUTOR_APPLICATION_NOT_APPROVED',
                'La solicitud no cuenta con visita y evaluación favorables.',
                409,
            );
        }
    }

    public function validarSolicitud(DistributorApplication $solicitud, ApplicationAuthorization $autorizacion): void
    {
        if ($solicitud->status !== ApplicationStatus::AUTHORIZED_PENDING_ACTIVATION
            || $autorizacion->decision !== ApplicationAuthorizationDecision::APPROVED) {
            throw new ExcepcionDistribuidora(
                'DISTRIBUTOR_APPLICATION_NOT_APPROVED',
                'La solicitud no cuenta con una autorización gerencial favorable.',
                409,
            );
        }

        if ($autorizacion->initial_credit_line_amount === null
            || bccomp($autorizacion->initial_credit_line_amount, '0', 4) <= 0) {
            throw new ExcepcionDistribuidora(
                'DISTRIBUTOR_INITIAL_CREDIT_INVALID',
                'La línea inicial autorizada debe ser mayor que cero.',
                422,
                ['initial_credit_line_amount' => ['El importe autorizado no es válido.']],
            );
        }
    }

    public function validarSucursal(Branch $sucursal, DistributorApplication $solicitud): void
    {
        if ($sucursal->id !== $solicitud->branch_id) {
            throw new ExcepcionDistribuidora('DISTRIBUTOR_BRANCH_MISMATCH', 'La sucursal no coincide con la solicitud.', 409);
        }

        if ($sucursal->status instanceof BaseStatus ? $sucursal->status !== BaseStatus::ACTIVE : $sucursal->status !== 'ACTIVE') {
            throw new ExcepcionDistribuidora('DISTRIBUTOR_BRANCH_MISMATCH', 'La sucursal de la solicitud no está activa.', 409);
        }
    }

    public function validarCoordinador(User $coordinador, DistributorApplication $solicitud): void
    {
        $alcanceValido = $coordinador->state === 'ACTIVE'
            && $coordinador->roleScopes()
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->where('branch_id', $solicitud->branch_id)
                ->whereHas('role', fn ($consulta) => $consulta->where('code', 'coordinator'))
                ->exists();

        if (! $alcanceValido || $coordinador->id !== $solicitud->coordinator_id) {
            throw new ExcepcionDistribuidora(
                'DISTRIBUTOR_COORDINATOR_SCOPE_INVALID',
                'El coordinador no está activo o no pertenece a la sucursal autorizada.',
                409,
            );
        }
    }

    public function validarCategoria(CategoryVersion $version): void
    {
        $this->validarCategoriaEnFecha($version, now());
    }

    public function validarCategoriaEnFecha(CategoryVersion $version, CarbonInterface $fecha): void
    {
        $publicada = $version->status instanceof VersionStatus
            ? $version->status === VersionStatus::PUBLISHED
            : $version->status === 'PUBLISHED';

        if (! $publicada) {
            throw new ExcepcionDistribuidora(
                'DISTRIBUTOR_CATEGORY_NOT_PUBLISHED',
                'La categoría seleccionada no está publicada.',
                422,
                ['category_version_id' => ['La versión no puede utilizarse.']],
            );
        }

        if ($version->effective_from->isAfter($fecha)
            || ($version->effective_to !== null && ! $version->effective_to->isAfter($fecha))) {
            throw new ExcepcionDistribuidora(
                'DISTRIBUTOR_CATEGORY_NOT_EFFECTIVE',
                'La categoría seleccionada no está vigente.',
                422,
                ['category_version_id' => ['La versión no está vigente.']],
            );
        }

        $categoriaActiva = $version->category->status instanceof BaseStatus
            ? $version->category->status === BaseStatus::ACTIVE
            : $version->category->status === 'ACTIVE';

        if (! $categoriaActiva) {
            throw new ExcepcionDistribuidora(
                'DISTRIBUTOR_CATEGORY_NOT_EFFECTIVE',
                'La categoría seleccionada está inactiva.',
                422,
                ['category_version_id' => ['La categoría está inactiva.']],
            );
        }
    }
}
