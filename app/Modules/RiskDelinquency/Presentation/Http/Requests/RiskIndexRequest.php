<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Presentation\Http\Requests;

use App\Modules\RiskDelinquency\Domain\Enums\DelinquencyStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RemovalRequestStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RiskAlertStatus;
use App\Modules\RiskDelinquency\Domain\Enums\RiskAlertType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RiskIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['sometimes', 'uuid'],
            'coordinator_id' => ['sometimes', 'uuid'],
            'distributor_id' => ['sometimes', 'uuid'],
            'delinquency_status' => ['sometimes', Rule::enum(DelinquencyStatus::class)],
            'financially_regularized' => ['sometimes', 'boolean'],
            'consecutive_breaches' => ['sometimes', 'integer', 'min:0'],
            'type' => ['sometimes', Rule::enum(RiskAlertType::class)],
            'status' => ['sometimes', Rule::in([
                ...array_column(RiskAlertStatus::cases(), 'value'),
                ...array_column(RemovalRequestStatus::cases(), 'value'),
            ])],
            'detected_from' => ['sometimes', 'date'],
            'detected_to' => ['sometimes', 'date', 'after_or_equal:detected_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
