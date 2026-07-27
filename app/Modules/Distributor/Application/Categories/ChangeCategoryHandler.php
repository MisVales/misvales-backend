<?php

namespace App\Modules\Distributor\Application\Categories;

use App\Modules\Distributor\Application\Contracts\CategoryModuleContract;
use App\Modules\Distributor\Domain\Distributors\DistributorDomainException;
use App\Modules\Distributor\Persistence\Models\Distributor;
use App\Modules\Distributor\Persistence\Models\DistributorCategoryAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChangeCategoryHandler
{
    public function __construct(
        private CategoryModuleContract $categoryModule
    ) {
    }

    public function handle(
        string $distributorId,
        string $categoryVersionId,
        string $reason,
        int $lockVersion,
        string $assignedBy,
        string $assignedRole,
        string $assignedBranchId,
        string $idempotencyKey
    ): array {
        return DB::transaction(function () use (
            $distributorId, $categoryVersionId, $reason, $lockVersion,
            $assignedBy, $assignedRole, $assignedBranchId, $idempotencyKey
        ) {
            // Idempotencia
            $existingAssignment = DistributorCategoryAssignment::where('idempotency_key', $idempotencyKey)->first();
            if ($existingAssignment) {
                if ($existingAssignment->category_version_id !== $categoryVersionId || $existingAssignment->distributor_id !== $distributorId) {
                    throw DistributorDomainException::idempotencyKeyReused();
                }
                return $existingAssignment->toArray();
            }

            // Bloquear distribuidora
            $distributor = Distributor::where('id', $distributorId)->lockForUpdate()->first();
            if (!$distributor) {
                throw DistributorDomainException::notFound();
            }

            // Validar lock version
            if ($distributor->lock_version !== $lockVersion) {
                throw DistributorDomainException::versionConflict();
            }

            // Obtener asignación vigente y bloquearla
            $currentAssignment = DistributorCategoryAssignment::where('distributor_id', $distributorId)
                ->whereNull('effective_to')
                ->lockForUpdate()
                ->first();

            if (!$currentAssignment) {
                throw DistributorDomainException::categoryRequired();
            }

            if ($currentAssignment->category_version_id === $categoryVersionId) {
                throw DistributorDomainException::categoryAlreadyAssigned();
            }

            // Obtener versión validada desde M03
            $categoryInfo = $this->categoryModule->getAssignableCategoryVersion($categoryVersionId);

            $now = now();

            // Cerrar asignación actual
            $currentAssignment->effective_to = $now;
            $currentAssignment->save();

            // Crear nueva asignación
            $newAssignment = DistributorCategoryAssignment::create([
                'id' => Str::uuid()->toString(),
                'distributor_id' => $distributor->id,
                'category_id' => $categoryInfo->categoryId,
                'category_version_id' => $categoryInfo->versionId,
                'profit_rate_snapshot' => $categoryInfo->profitRate,
                'effective_from' => $now,
                'effective_to' => null,
                'assigned_by' => $assignedBy,
                'assigned_role' => $assignedRole,
                'assigned_branch_id' => $assignedBranchId,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
            ]);

            // Actualizar lock version de distribuidora
            $distributor->lock_version += 1;
            $distributor->save();

            // Registrar evento DistributorCategoryChanged (Pendiente por M18)

            return $newAssignment->toArray();
        });
    }
}
