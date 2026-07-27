<?php

declare(strict_types=1);

namespace App\Modules\Relation\Application\Commands\GenerateRelation;

use App\Modules\Relation\Application\Contracts\InstallmentCycleResolver;
use App\Modules\Relation\Application\Contracts\PaymentReferenceGenerator;
use App\Modules\Relation\Domain\Enums\CutAttemptStatus;
use App\Modules\Relation\Domain\Enums\FinancialStatus;
use App\Modules\Relation\Domain\Enums\PaymentBehavior;
use App\Modules\Relation\Domain\Events\RelationGenerated;
use App\Modules\Relation\Domain\Events\RelationGenerationFailed;
use App\Modules\Relation\Infrastructure\Persistence\Models\CutRun;
use App\Modules\Relation\Infrastructure\Persistence\Models\CutRunDistributor;
use App\Modules\Relation\Infrastructure\Persistence\Models\Relation;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateRelationHandler
{
    public function __construct(
        private readonly InstallmentCycleResolver $cycleResolver,
        private readonly PaymentReferenceGenerator $referenceGenerator
    ) {
    }

    public function handle(GenerateRelationCommand $command): void
    {
        try {
            DB::transaction(function () use ($command) {
                // 1. Resolver la corrida y el intento.
                $cutRun = CutRun::findOrFail($command->cutRunId);
                
                // 2. Bloquear el intento por distribuidora.
                $attempt = CutRunDistributor::where('cut_run_id', $cutRun->id)
                    ->where('distributor_id', $command->distributorId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($attempt->status === CutAttemptStatus::GENERADA) {
                    return; // Ya confirmada
                }

                // 3. Buscar una relación existente para la misma distribuidora y corte.
                $existingRelation = Relation::where('distributor_id', $command->distributorId)
                    ->where('cut_date', $cutRun->cut_date)
                    ->lockForUpdate()
                    ->first();

                // 4. Si ya existe y está confirmada, devolverla.
                if ($existingRelation) {
                    $attempt->update(['status' => CutAttemptStatus::GENERADA, 'relation_id' => $existingRelation->id]);
                    return;
                }

                // 5. Bloquear la distribuidora y las asignaciones históricas necesarias.
                // 6. Obtener el snapshot de línea y puntos para presentación.
                // 7. Bloquear las parcialidades elegibles.
                // (Pending implementation of actual models, using dummy logic for now)
                
                $eligibleInstallments = $this->cycleResolver->resolveEligibleInstallments(
                    $command->distributorId,
                    $cutRun->cut_date
                );

                if (empty($eligibleInstallments)) {
                    $attempt->update(['status' => CutAttemptStatus::SIN_PARTIDAS]);
                    return;
                }

                // 8-9. Confirmar que ninguna pertenece a otra relación y validar integridad.
                // 10. Generar una referencia única.
                $reference = $this->referenceGenerator->generateFor('new_relation_id', $command->distributorId);

                // 11. Crear la relación.
                $relation = Relation::create([
                    'cut_run_id' => $cutRun->id,
                    'distributor_id' => $command->distributorId,
                    'branch_id' => 'dummy-branch-id', // Pending real resolution
                    'coordinator_id' => 'dummy-coordinator-id',
                    'cut_date' => $cutRun->cut_date,
                    'cut_at' => now(),
                    'early_payment_starts_at' => now(),
                    'early_payment_ends_at' => now()->addDays(2),
                    'due_at' => now()->addDays(20)->endOfDay(),
                    'payment_reference' => $reference->getReference(),
                    'financial_status' => FinancialStatus::PENDIENTE,
                    'payment_behavior' => PaymentBehavior::SIN_CLASIFICAR,
                    'portfolio_total' => 0, // Pending calculation
                    'initial_misvales_due_total' => 0,
                    'outstanding_balance' => 0,
                    'products_capital_basis' => 0,
                ]);

                // 12-18. Crear snapshot, partidas, actualizar totales...
                
                $attempt->update([
                    'status' => CutAttemptStatus::GENERADA,
                    'relation_id' => $relation->id,
                ]);

                // 20. Crear auditoría y evento de outbox.
                event(new RelationGenerated(
                    $relation->id,
                    $command->distributorId,
                    $cutRun->id,
                    $relation->due_at->toIso8601String(),
                    $relation->payment_reference,
                    (string)$relation->portfolio_total,
                    (string)$relation->initial_misvales_due_total
                ));
            });
        } catch (Throwable $e) {
            event(new RelationGenerationFailed(
                $command->cutRunId,
                $command->distributorId,
                'RELATION_GENERATION_ERROR'
            ));
            throw $e;
        }
    }
}
