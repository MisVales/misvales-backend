<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Application\Products;

use App\Modules\Configuration\Application\DTOs\CreateProductData;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use App\Modules\Configuration\Domain\Events\ProductCreated;
use App\Modules\Configuration\Domain\Exceptions\ConfigurationException;
use App\Modules\Configuration\Domain\ValueObjects\Money;
use App\Modules\Configuration\Domain\ValueObjects\Percentage;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ConfigurationAuditEventModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ProductModel;
use App\Modules\Configuration\Infrastructure\Persistence\Models\ProductVersionModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Crea un producto con su primer borrador (C09).
 */
final class CreateProductUseCase
{
    public function execute(CreateProductData $data): ProductVersionModel
    {
        $amount = new Money($data->amount);
        $loanCommission = new Percentage($data->loanCommissionRate);
        $interestRate = new Percentage($data->interestRatePerFortnight);
        $insurance = new Money($data->insuranceAmount);

        if (! $amount->isMultipleOf(100)) {
            throw ConfigurationException::productAmountNotMultipleOf100();
        }

        return DB::transaction(function () use ($data, $amount, $loanCommission, $interestRate, $insurance): ProductVersionModel {
            $now = CarbonImmutable::now();
            $correlationId = (string) Str::uuid();

            // Identidad base
            $product = new ProductModel;
            $product->public_id = (string) Str::uuid();
            $product->status = VersionStatus::DRAFT->value;
            $product->created_by = $data->actorUserId;
            $product->save();

            // Primer borrador
            $version = new ProductVersionModel;
            $version->public_id = (string) Str::uuid();
            $version->product_id = $product->id;
            $version->version_number = 1;
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
                'event_type' => 'PRODUCT_CREATED',
                'result' => 'SUCCESS',
                'actor_user_id' => $data->actorUserId,
                'executor_user_id' => $data->actorUserId,
                'resource_type' => 'product',
                'resource_id' => $product->public_id,
                'after_state' => [
                    'amount' => $amount->databaseValue(),
                    'loan_commission_rate' => $loanCommission->databaseValue(),
                    'interest_rate_per_fortnight' => $interestRate->databaseValue(),
                    'insurance_amount' => $insurance->databaseValue(),
                    'fortnight_count' => $data->fortnightCount,
                ],
                'status_after' => VersionStatus::DRAFT->value,
                'version_after' => '1',
                'correlation_id' => $correlationId,
                'request_id' => $data->idempotencyKey,
                'occurred_at' => $now,
            ]);

            ProductCreated::dispatch(
                $product->public_id,
                $version->public_id,
                1,
                (string) $data->actorUserId,
                $now->toIso8601String(),
            );

            return $version;
        });
    }
}
