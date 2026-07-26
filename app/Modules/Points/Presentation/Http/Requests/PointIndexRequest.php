<?php

declare(strict_types=1);

namespace App\Modules\Points\Presentation\Http\Requests;

use App\Modules\Points\Domain\Enums\PointLedgerType;
use App\Modules\Points\Domain\Enums\PointRedemptionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PointIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'type' => ['sometimes', Rule::enum(PointLedgerType::class)],
            'status' => ['sometimes', Rule::enum(PointRedemptionStatus::class)],
            'relation_id' => ['sometimes', 'uuid'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
        ];
    }
}
