<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Services;

use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherFinancialSnapshotModel;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherModel;

/** Construye el recurso histórico sin volver a consultar configuraciones vigentes. */
final class VoucherDataBuilder
{
    /** @return array<string, mixed> */
    public function build(VoucherModel $voucher, bool $includeInstallments = true): array
    {
        $voucher->loadMissing(['financialSnapshot', 'branch', 'generator']);
        if ($includeInstallments) {
            $voucher->loadMissing('installments');
        }
        $snapshot = $this->snapshot($voucher);
        $embedded = $voucher->financial_snapshot;

        return [
            'id' => $voucher->id,
            'voucher_id' => $voucher->id,
            'folio' => $voucher->folio,
            'type' => $voucher->type->value,
            'status' => $voucher->status->value,
            'client_id' => $voucher->client_id,
            'client' => [
                'id' => $voucher->client_id,
                'name' => $voucher->client_name_snapshot,
            ],
            'distributor_id' => $voucher->distributor_id,
            'distributor' => [
                'id' => $voucher->distributor_id,
                'user_id' => $voucher->distributor_user_id,
            ],
            'branch' => [
                'id' => $voucher->branch?->public_id,
                'name' => $voucher->branch?->name,
            ],
            'branch_id' => $voucher->branch_id,
            'product_id' => $voucher->product_id,
            'product_version_id' => $voucher->product_version_id,
            'product' => [
                'id' => $voucher->product_id,
                'version_id' => $voucher->product_version_id,
                'version' => $this->snapshotValue(
                    $voucher,
                    'product_version',
                    data_get($embedded, 'product.version'),
                ),
                'name' => $this->snapshotValue($voucher, 'product_name', data_get($embedded, 'product.name')),
                'capital' => self::money($voucher->capital_amount),
            ],
            'category' => [
                'id' => $voucher->category_id,
                'version_id' => $voucher->category_version_id,
                'version' => $this->snapshotValue(
                    $voucher,
                    'category_version',
                    data_get($embedded, 'category.version'),
                ),
                'name' => $this->snapshotValue($voucher, 'category_name', data_get($embedded, 'category.name')),
                'profit_rate' => $this->snapshotValue(
                    $voucher,
                    'distributor_profit_rate',
                    data_get($embedded, 'distributor_profit_rate'),
                ),
            ],
            'financial_summary' => $this->financialSummary($snapshot, $embedded),
            'financial_snapshot' => $embedded,
            'credit_validation' => [
                'available_before_fulfillment' => self::money($voucher->credit_available_snapshot),
                'special_rule_applied' => $voucher->credit_usage_restriction_id !== null,
                'restriction_id' => $voucher->creditRestriction?->public_id,
                'reference' => self::nullableMoney($voucher->restriction_reference_snapshot),
                'minimum_allowed' => self::nullableMoney($voucher->restriction_minimum_snapshot),
                'maximum_allowed' => self::nullableMoney($voucher->restriction_maximum_snapshot),
            ],
            'installments' => $includeInstallments
                ? $voucher->installments->map(static fn ($installment): array => [
                    'id' => $installment->id,
                    'payment_number' => $installment->payment_number,
                    'total_payments' => $installment->total_payments,
                    'capital' => self::money($installment->capital_amount),
                    'loan_commission' => self::money($installment->loan_commission_amount),
                    'interest' => self::money($installment->interest_amount),
                    'insurance' => self::money($installment->insurance_amount),
                    'base_payment' => self::money($installment->base_payment_amount),
                    'distributor_profit' => self::money($installment->distributor_profit_amount),
                    'client_total' => self::money($installment->client_total_amount),
                    'misvales_due' => self::money($installment->misvales_due_amount),
                    'relation_status' => $installment->relation_status,
                    'relation_item_id' => $installment->relation_item_id,
                ])->all()
                : [],
            'generated_by' => [
                'id' => $voucher->generator?->public_id,
                'name' => $voucher->generator?->name,
            ],
            'generated_at' => $voucher->generated_at->setTimezone('America/Monterrey')->toIso8601String(),
            'released_at' => $voucher->released_at?->toIso8601String(),
            'fulfilled_at' => $voucher->fulfilled_at?->toIso8601String(),
            'lock_version' => $voucher->lock_version,
        ];
    }

    /** @param array<string, mixed> $embedded
     * @return array<string, mixed>
     */
    private function financialSummary(?VoucherFinancialSnapshotModel $snapshot, array $embedded): array
    {
        if ($snapshot === null) {
            return $embedded;
        }

        return [
            'loan_commission' => self::money($snapshot->loan_commission_amount),
            'total_interest' => self::money($snapshot->total_interest_amount),
            'insurance' => self::money($snapshot->insurance_amount),
            'misvales_total' => self::money($snapshot->misvales_total),
            'distributor_profit' => self::money($snapshot->distributor_profit_amount),
            'client_total' => self::money($snapshot->client_total),
            'base_installment' => self::money($snapshot->base_installment_amount),
            'profit_installment' => self::money($snapshot->profit_installment_amount),
            'client_installment' => self::money($snapshot->client_installment_amount),
            'installments' => $snapshot->fortnights,
            'calculation_version' => $snapshot->calculation_version,
            'internal_precision' => $snapshot->internal_precision,
            'rounding_rule' => $snapshot->rounding_rule,
        ];
    }

    private function snapshot(VoucherModel $voucher): ?VoucherFinancialSnapshotModel
    {
        $snapshot = $voucher->getRelation('financialSnapshot');

        return $snapshot instanceof VoucherFinancialSnapshotModel ? $snapshot : null;
    }

    private function snapshotValue(VoucherModel $voucher, string $attribute, mixed $fallback): mixed
    {
        $snapshot = $voucher->getRelation('financialSnapshot');
        if (! $snapshot instanceof VoucherFinancialSnapshotModel) {
            return $fallback;
        }

        return $snapshot->getAttribute($attribute) ?? $fallback;
    }

    private static function money(string|float $value): string
    {
        return bcadd((string) $value, '0', 2);
    }

    private static function nullableMoney(string|float|null $value): ?string
    {
        return $value === null ? null : self::money($value);
    }
}
