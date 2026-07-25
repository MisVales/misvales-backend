<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Products;

use App\Modules\Configuration\Application\DTOs\CreateProductVersionData;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Domain\ValueObjects\Money;
use App\Modules\Configuration\Domain\ValueObjects\Percentage;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationAuditEventModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ProductVersionModel;
use App\Modules\Configuration\Infrastructure\Persistence\Repositories\EloquentProductRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea un nuevo borrador de versión para un producto existente.
 */
final class CreateProductVersionUseCase
{
    public function __construct(
        private readonly EloquentProductRepository $repository,
    ) {}

    public function execute(CreateProductVersionData $data): ProductVersionModel
    {
        $amount = new Money($data->amount);
        $loanCommission = new Percentage($data->loanCommissionRate);
        $interestRate = new Percentage($data->interestRatePerFortnight);
        $insurance = new Money($data->insuranceAmount);

        if (! $amount->isMultipleOf(100)) {
            throw ConfigurationException::productAmountNotMultipleOf100();
        }

        return DB::transaction(function () use ($data, $amount, $loanCommission, $interestRate, $insurance): ProductVersionModel {
            $product = $this->repository->lockById($data->productPublicId);

            if ($product === null) {
                throw ConfigurationException::productNotFound();
            }

            if ($product->status === VersionStatus::INACTIVE->value) {
                throw ConfigurationException::productInactive();
            }

            $versionNumber = $this->repository->nextVersionNumber($product);
            $now = CarbonImmutable::now();
            $correlationId = (string) Str::uuid();

            $version = new ProductVersionModel();
            $version->public_id = (string) Str::uuid();
            $version->product_id = $product->id;
            $version->version_number = $versionNumber;
            $version->amount = $amount->databaseValue();
            $version->loan_commission_rate = $loanCommission->databaseValue();
            $version->interest_rate_per_fortnight = $interestRate->databaseValue();
            $version->insurance_amount = $insurance->databaseValue();
            $version->fortnight_count = $data->fortnightCount;
            $version->status = VersionStatus::DRAFT->value;
            $version->created_by = $data->actorUserId;
            $version->save();

            ConfigurationAuditEventModel::query()->create([
                'public_id' => (string) Str::uuid(),
                'event_type' => 'PRODUCT_DRAFT_CREATED',
                'result' => 'SUCCESS',
                'actor_user_id' => $data->actorUserId,
                'executor_user_id' => $data->actorUserId,
                'resource_type' => 'product_version',
                'resource_id' => $version->public_id,
                'after_state' => [
                    'amount' => $amount->databaseValue(),
                    'loan_commission_rate' => $loanCommission->databaseValue(),
                    'interest_rate_per_fortnight' => $interestRate->databaseValue(),
                    'insurance_amount' => $insurance->databaseValue(),
                    'fortnight_count' => $data->fortnightCount,
                    'version_number' => $versionNumber,
                ],
                'status_after' => VersionStatus::DRAFT->value,
                'version_after' => (string) $versionNumber,
                'correlation_id' => $correlationId,
                'request_id' => $data->idempotencyKey,
                'occurred_at' => $now,
            ]);

            return $version;
        });
    }
}
