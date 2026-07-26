<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Presentation\Http\Requests;

use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SearchVouchersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'folio' => ['nullable', 'string', 'max:100'],
            'client_name' => ['nullable', 'string', 'min:2', 'max:200'],
            'status' => ['nullable', Rule::enum(VoucherStatus::class)],
            'generated_from' => ['nullable', 'date_format:Y-m-d'],
            'generated_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:generated_from'],
            'sort' => ['nullable', Rule::in(['folio', 'status', 'generated_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
