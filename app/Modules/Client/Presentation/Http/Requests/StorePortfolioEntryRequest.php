<?php

declare(strict_types=1);

namespace App\Modules\Client\Presentation\Http\Requests;

use App\Modules\Client\Domain\Portfolio\PortfolioEntryType;
use App\Modules\Client\Domain\Portfolio\PortfolioStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Valida únicamente movimientos de cartera que una distribuidora puede capturar. */
final class StorePortfolioEntryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'entry_type' => ['required', Rule::in([
                PortfolioEntryType::PAYMENT->value,
                PortfolioEntryType::INSTALLMENT->value,
                PortfolioEntryType::STATUS_UPDATE->value,
                PortfolioEntryType::NOTE->value,
            ])],
            'amount' => ['nullable', 'decimal:0,4', 'gt:0'],
            'informational_status' => ['required', Rule::enum(PortfolioStatus::class)],
            'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:150'],
            'client_id' => ['prohibited'],
            'distributor_id' => ['prohibited'],
            'assignment_id' => ['prohibited'],
            'voucher_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'lock_version' => ['prohibited'],
            'delinquent' => ['prohibited'],
        ];
    }
}
