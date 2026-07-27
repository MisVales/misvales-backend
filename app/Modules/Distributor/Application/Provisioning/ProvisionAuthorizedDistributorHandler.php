<?php

namespace App\Modules\Distributor\Application\Provisioning;

use App\Modules\Distributor\Application\Contracts\ProvisionAuthorizedDistributor;
use App\Modules\Distributor\Application\Contracts\ProvisionAuthorizedDistributorCommand;
use App\Modules\Distributor\Domain\Distributors\DistributorDomainException;
use App\Modules\Distributor\Domain\Distributors\DistributorStatus;
use App\Modules\Distributor\Persistence\Models\Distributor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProvisionAuthorizedDistributorHandler implements ProvisionAuthorizedDistributor
{
    // Aquí idealmente inyectaríamos el generador de números y un repositorio
    // public function __construct(private VisibleNumberGenerator $numberGenerator) {}

    public function handle(ProvisionAuthorizedDistributorCommand $command): string
    {
        return DB::transaction(function () use ($command) {
            // 1. Idempotencia: Si ya existe esta operación, devolver el mismo UUID
            $existingByOperation = Distributor::where('activation_operation_id', $command->operationId)->first();
            if ($existingByOperation) {
                // Validación estricta de idempotencia para la misma solicitud
                if ($existingByOperation->onboarding_application_id !== $command->onboardingApplicationId) {
                    throw DistributorDomainException::idempotencyKeyReused();
                }
                return $existingByOperation->id;
            }

            // 2. Validar que la solicitud no tenga otra distribuidora
            $existingByApplication = Distributor::where('onboarding_application_id', $command->onboardingApplicationId)->first();
            if ($existingByApplication) {
                throw DistributorDomainException::alreadyProvisioned();
            }

            // 3. Validar que la cuenta no esté vinculada a otra distribuidora
            $existingByUser = Distributor::where('user_id', $command->userId)->first();
            if ($existingByUser) {
                throw DistributorDomainException::accountAlreadyLinked();
            }

            // 4. Generar número visible (Mock/Placeholder ya que no hay formato final definido en DI02)
            $distributorNumber = 'D-' . strtoupper(Str::random(8));

            // Colisión check (teóricamente manejado por el generador o constraint en db)
            if (Distributor::where('distributor_number', $distributorNumber)->exists()) {
                throw DistributorDomainException::numberConflict();
            }

            // 5. Crear el perfil con estado ACTIVE
            $distributor = Distributor::create([
                'id' => Str::uuid()->toString(),
                'distributor_number' => $distributorNumber,
                'onboarding_application_id' => $command->onboardingApplicationId,
                'user_id' => $command->userId,
                'branch_id' => $command->branchId,
                'status' => DistributorStatus::ACTIVE->value,
                'activated_at' => $command->activatedAt->format('Y-m-d H:i:s'),
                'activated_by' => $command->activatedBy,
                'activation_operation_id' => $command->operationId,
                'lock_version' => 1,
            ]);

            // 6. Registrar evento pendiente (Outbox/Audit - Mock/Placeholder para M18)
            // EventPublisher::publish(new DistributorProvisioned(...));
            
            return $distributor->id;
        });
    }
}
