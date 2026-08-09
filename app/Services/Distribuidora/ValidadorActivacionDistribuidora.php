<?php

namespace App\Services\Distribuidora;

use App\Enums\ApplicationAuthorizationDecision;
use App\Enums\ApplicationStatus;
use App\Enums\BaseStatus;
use App\Enums\VersionStatus;
use App\Exceptions\ExcepcionDistribuidora;
use App\Models\ApplicationAuthorization;
use App\Models\Branch;
use App\Models\CategoryVersion;
use App\Models\DistributorApplication;
use App\Models\User;
use Carbon\CarbonInterface;

class ValidadorActivacionDistribuidora
{
    public function validarSolicitud(DistributorApplication $solicitud, ApplicationAuthorization $autorizacion): void
    {
        if ($solicitud->status !== ApplicationStatus::AUTHORIZED_PENDING_ACTIVATION
            || $autorizacion->decision !== ApplicationAuthorizationDecision::APPROVED) {
            throw new ExcepcionDistribuidora(
                'DISTRIBUTOR_APPLICATION_NOT_APPROVED',
                'La solicitud no cuenta con una autorizaciÃ³n formal favorable de M05.',
                409,
            );
        }
    }

    public function validarSucursal(Branch $sucursal, DistributorApplication $solicitud): void
    {
        if ($sucursal->id !== $solicitud->branch_id) {
            throw new ExcepcionDistribuidora('DISTRIBUTOR_BRANCH_MISMATCH', 'La sucursal no coincide con la autorizaciÃ³n.', 409);
        }

        if ($sucursal->status instanceof BaseStatus ? $sucursal->status !== BaseStatus::ACTIVE : $sucursal->status !== 'ACTIVE') {
            throw new ExcepcionDistribuidora('DISTRIBUTOR_BRANCH_MISMATCH', 'La sucursal autorizada no estÃ¡ activa.', 409);
        }
    }

    public function validarCoordinador(User $coordinador, DistributorApplication $solicitud): void
    {
        $alcanceValido = $coordinador->state === 'ACTIVE'
            && $coordinador->roleScopes()
                ->where('status', 'ACTIVE')
                ->whereNull('revoked_at')
                ->where('scope_type', 'BRANCH')
                ->where('branch_id', $solicitud->branch_id)
                ->whereHas('role', fn ($consulta) => $consulta->where('code', 'coordinator'))
                ->exists();

        if (! $alcanceValido || $coordinador->id !== $solicitud->coordinator_id) {
            throw new ExcepcionDistribuidora(
                'DISTRIBUTOR_COORDINATOR_SCOPE_INVALID',
                'El coordinador no estÃ¡ activo o no pertenece a la sucursal autorizada.',
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
                'La categorÃ­a seleccionada no estÃ¡ publicada.',
                422,
                ['category_version_id' => ['La versiÃ³n no puede utilizarse.']],
            );
        }

        if ($version->effective_from->isAfter($fecha)
            || ($version->effective_to !== null && ! $version->effective_to->isAfter($fecha))) {
            throw new ExcepcionDistribuidora(
                'DISTRIBUTOR_CATEGORY_NOT_EFFECTIVE',
                'La categorÃ­a seleccionada no estÃ¡ vigente.',
                422,
                ['category_version_id' => ['La versiÃ³n no estÃ¡ vigente.']],
            );
        }

        $categoriaActiva = $version->category->status instanceof BaseStatus
            ? $version->category->status === BaseStatus::ACTIVE
            : $version->category->status === 'ACTIVE';
        if (! $categoriaActiva) {
            throw new ExcepcionDistribuidora(
                'DISTRIBUTOR_CATEGORY_NOT_EFFECTIVE',
                'La categorÃ­a seleccionada estÃ¡ inactiva.',
                422,
                ['category_version_id' => ['La categorÃ­a estÃ¡ inactiva.']],
            );
        }
    }
}
