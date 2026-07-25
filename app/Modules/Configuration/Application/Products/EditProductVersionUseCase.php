<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Products;

use App\Modules\Configuration\Application\DTOs\EditProductVersionData;
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
 * Edita un borrador de producto.
 */
final class EditProductVersionUseCase
{
    public function __construct(
        private readonly EloquentProductRepository $repository,
    ) {}

    public function execute(EditProductVersionData $data): ProductVersionModel
    {
        $amount = new Money($data->amount);
        $loanCommission = new Percentage($data->loanCommissionRate);
        $interestRate = new Percentage($data->interestRatePerFortnight);
        $insurance = new Money($data->insuranceAmount);

        if (! $amount->isMultipleOf(100)) {
            throw ConfigurationException::productAmountNotMultipleOf100();
        }

        return DB::transaction(function () use ($data, $amount, $loanCommission, $interestRate, $insurance): ProductVersionModel {
            $version = $this->repository->lockVersion($data->versionPublicId);

            if ($version === null) {
                throw ConfigurationException::productNotFound();
            }

            if ($version->versionStatus() !== VersionStatus::DRAFT) {
                throw ConfigurationException::immutable();
            }

            if ($version->lock_version !== $data->lockVersion) {
                throw ConfigurationException::versionConflict();
            }

            $now = CarbonImmutable::now();
            $correlationId = (string) Str::uuid();

            $beforeState = [
                'amount' => $version->amount,
                'loan_commission_rate' => $version->loan_commission_rate,
                'interest_rate_per_fortnight' => $version->interest_rate_per_fortnight,
                'insurance_amount' => $version->insurance_amount,
                'fortnight_count' => $version->fortnight_count,
            ];

            $version->amount = $amount->databaseValue();
            $version->loan_commission_rate = $loanCommission->databaseValue();
            $version->interest_rate_per_fortnight = $interestRate->databaseValue();
            $version->insurance_amount = $insurance->databaseValue();
            $version->fortnight_count = $data->fortnightCount;
            $version->lock_version = $version->lock_version + 1;
            $version->save();

            ConfigurationAuditEventModel::query()->create([
                'public_id' => (string) Str::uuid(),
                'event_type' => 'PRODUCT_DRAFT_EDITED',
                'result' => 'SUCCESS',
                'actor_user_id' => $data->actorUserId,
                'executor_user_id' => $data->actorUserId,
                'resource_type' => 'product_version',
                'resource_id' => $version->public_id,
                'before_state' => $beforeState,
                'after_state' => [
                    'amount' => $amount->databaseValue(),
                    'loan_commission_rate' => $loanCommission->databaseValue(),
                    'interest_rate_per_fortnight' => $interestRate->databaseValue(),
                    'insurance_amount' => $insurance->databaseValue(),
                    'fortnight_count' => $data->fortnightCount,
                ],
                'status_before' => VersionStatus::DRAFT->value,
                'status_after' => VersionStatus::DRAFT->value,
                'version_after' => (string) $version->version_number,
                'correlation_id' => $correlationId,
                'request_id' => (string) Str::uuid(),
                'occurred_at' => $now,
            ]);

            return $version;
        });
    }
}
