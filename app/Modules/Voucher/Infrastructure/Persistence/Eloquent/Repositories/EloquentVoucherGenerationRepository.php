<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Repositories;

use App\Modules\Voucher\Application\Contracts\VoucherGenerationRepository;
use App\Modules\Voucher\Application\DTOs\GeneratedVoucherData;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\ValueObjects\Money;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherFinancialSnapshotModel;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherInstallmentModel;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherModel;
use Illuminate\Support\Str;

/** Persiste vale, snapshot y parcialidades dentro de la transacción del caso de uso. */
final class EloquentVoucherGenerationRepository implements VoucherGenerationRepository
{
    public function clientHasHistory(string $clientId): bool
    {
        return VoucherModel::query()->where('client_id', $clientId)->exists();
    }

    public function create(GeneratedVoucherData $data): VoucherModel
    {
        $credit = $data->credit;
        $range = $credit->restrictionRange;
        $calculation = $data->calculation;
        $snapshot = [
            'product' => [
                'id' => $data->product->productId,
                'version_id' => $data->product->versionId,
                'version' => $data->product->version,
                'name' => $data->product->name,
            ],
            'category' => [
                'id' => $data->category->categoryId,
                'version_id' => $data->category->versionId,
                'version' => $data->category->version,
                'name' => $data->category->name,
            ],
            'capital' => $calculation->capital->format(),
            'loan_commission_rate' => $data->product->commissionRate->value(),
            'loan_commission' => $calculation->loanCommission->format(),
            'interest_rate_per_fortnight' => $data->product->interestRate->value(),
            'total_interest' => $calculation->totalInterest->format(),
            'insurance' => $calculation->insurance->format(),
            'fortnights' => $calculation->payments,
            'distributor_profit_rate' => $data->category->profitRate->value(),
            'distributor_profit' => $calculation->distributorProfit->format(),
            'misvales_total' => $calculation->misvalesTotal->format(),
            'client_total' => $calculation->clientTotal->format(),
            'calculation_version' => 'M08-V1',
            'internal_precision' => Money::SCALE,
            'rounding_rule' => 'HALF_UP',
        ];

        $voucher = new VoucherModel;
        $voucher->forceFill([
            'id' => $data->id,
            'folio' => $data->folio,
            'type' => $data->type,
            'status' => VoucherStatus::GENERATED,
            'distributor_id' => $data->distributor->id,
            'distributor_user_id' => $data->distributor->userId,
            'client_id' => $data->client->id,
            'branch_id' => $data->distributor->branchId,
            'product_id' => $data->product->productId,
            'product_version_id' => $data->product->versionId,
            'category_id' => $data->category->categoryId,
            'category_version_id' => $data->category->versionId,
            'credit_line_id' => $credit->creditLineId,
            'credit_usage_restriction_id' => $credit->restrictionInternalId,
            'capital_amount' => $calculation->capital->databaseValue(),
            'credit_available_snapshot' => $credit->availableBalance->databaseValue(),
            'restriction_reference_snapshot' => $range?->reference->databaseValue(),
            'restriction_minimum_snapshot' => $range?->lower->databaseValue(),
            'restriction_maximum_snapshot' => $range?->upper->databaseValue(),
            'financial_snapshot' => $snapshot,
            'client_name_snapshot' => $data->client->displayName,
            'client_name_normalized' => mb_strtolower($data->client->displayName),
            'generated_by' => $data->generatedBy,
            'generated_at' => now('UTC'),
            'lock_version' => 1,
        ])->save();

        $financial = new VoucherFinancialSnapshotModel;
        $financial->forceFill([
            'id' => (string) Str::uuid(),
            'voucher_id' => $voucher->id,
            'product_id' => $data->product->productId,
            'product_version_id' => $data->product->versionId,
            'product_version' => $data->product->version,
            'product_name' => $data->product->name,
            'capital_amount' => $calculation->capital->databaseValue(),
            'loan_commission_rate' => $data->product->commissionRate->value(),
            'loan_commission_amount' => $calculation->loanCommission->databaseValue(),
            'fortnightly_interest_rate' => $data->product->interestRate->value(),
            'total_interest_amount' => $calculation->totalInterest->databaseValue(),
            'insurance_amount' => $calculation->insurance->databaseValue(),
            'fortnights' => $calculation->payments,
            'category_id' => $data->category->categoryId,
            'category_version_id' => $data->category->versionId,
            'category_version' => $data->category->version,
            'category_name' => $data->category->name,
            'distributor_profit_rate' => $data->category->profitRate->value(),
            'distributor_profit_amount' => $calculation->distributorProfit->databaseValue(),
            'misvales_total' => $calculation->misvalesTotal->databaseValue(),
            'base_installment_amount' => $calculation->baseInstallment->databaseValue(),
            'profit_installment_amount' => $calculation->profitInstallment->databaseValue(),
            'client_installment_amount' => $calculation->clientInstallment->databaseValue(),
            'client_total' => $calculation->clientTotal->databaseValue(),
            'calculation_version' => 'M08-V1',
            'internal_precision' => Money::SCALE,
            'rounding_rule' => 'HALF_UP',
            'created_at' => now('UTC'),
        ])->save();

        foreach ($calculation->installments as $installment) {
            $model = new VoucherInstallmentModel;
            $model->forceFill([
                'id' => (string) Str::uuid(),
                'voucher_id' => $voucher->id,
                'payment_number' => $installment['payment_number'],
                'total_payments' => $installment['total_payments'],
                'capital_amount' => $installment['capital']->databaseValue(),
                'loan_commission_amount' => $installment['loan_commission']->databaseValue(),
                'interest_amount' => $installment['interest']->databaseValue(),
                'insurance_amount' => $installment['insurance']->databaseValue(),
                'base_payment_amount' => $installment['base_payment']->databaseValue(),
                'distributor_profit_amount' => $installment['distributor_profit']->databaseValue(),
                'client_total_amount' => $installment['client_total']->databaseValue(),
                'misvales_due_amount' => $installment['misvales_due']->databaseValue(),
                'relation_status' => 'PENDIENTE',
                'created_at' => now('UTC'),
            ])->save();
        }

        return $voucher->load(['financialSnapshot', 'installments', 'branch', 'generator']);
    }
}
