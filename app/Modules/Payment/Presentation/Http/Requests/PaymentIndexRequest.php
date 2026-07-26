<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Requests;

use App\Modules\Payment\Domain\Enums\BankImportStatus;
use App\Modules\Payment\Domain\Enums\BankMovementStatus;
use App\Modules\Payment\Domain\Enums\ClarificationStatus;
use App\Modules\Payment\Domain\Enums\ExcessBalanceStatus;
use App\Modules\Payment\Domain\Enums\ManualReconciliationStatus;
use Illuminate\Contracts\Validation\Rule as ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Valida paginación y filtros comunes de los listados de M11. */
final class PaymentIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<ValidationRule|string>> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', $this->statusRule()],
            'business_date' => ['sometimes', 'date_format:Y-m-d'],
            'paid_at' => ['sometimes', 'date'],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'distributor_id' => ['sometimes', 'integer', 'min:1'],
            'relation_id' => ['sometimes', 'string', 'max:128'],
            'bank_import_id' => ['sometimes', 'uuid'],
            'bank_folio_normalized' => ['sometimes', 'string', 'max:160'],
            'payment_reference_normalized' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    private function statusRule(): ValidationRule|string
    {
        return match (true) {
            $this->routeIs('api.v1.bank-imports.index') => Rule::enum(BankImportStatus::class),
            $this->routeIs('api.v1.bank-imports.movements', 'api.v1.bank-movements.index') => Rule::enum(BankMovementStatus::class),
            $this->routeIs('api.v1.clarifications.index') => Rule::enum(ClarificationStatus::class),
            $this->routeIs('api.v1.manual-reconciliations.index') => Rule::enum(ManualReconciliationStatus::class),
            $this->routeIs('api.v1.excess-balances.index') => Rule::enum(ExcessBalanceStatus::class),
            default => 'prohibited',
        };
    }
}
