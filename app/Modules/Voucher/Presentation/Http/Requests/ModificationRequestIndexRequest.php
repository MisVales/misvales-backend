<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Presentation\Http\Requests;

use App\Modules\Voucher\Domain\Enums\DataChangeRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ModificationRequestIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(DataChangeRequestStatus::class)],
            'voucher_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
