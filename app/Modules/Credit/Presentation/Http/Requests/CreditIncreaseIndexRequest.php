<?php

declare(strict_types=1);

namespace App\Modules\Credit\Presentation\Http\Requests;

use App\Modules\Credit\Domain\Enums\IncreaseRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreditIncreaseIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(IncreaseRequestStatus::class)],
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'coordinator_id' => ['sometimes', 'integer', 'exists:users,id'],
            'distributor_id' => ['sometimes', 'integer', 'exists:users,id'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
