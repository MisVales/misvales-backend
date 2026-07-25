<?php

declare(strict_types=1);

namespace App\Modules\Credit\Presentation\Http\Requests;

use App\Modules\Credit\Domain\Enums\CreditMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreditMovementIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::enum(CreditMovementType::class)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'source_type' => ['sometimes', 'string', 'max:80'],
            'source_id' => ['sometimes', 'string', 'max:128'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
