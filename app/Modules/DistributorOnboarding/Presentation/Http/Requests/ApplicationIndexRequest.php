<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Presentation\Http\Requests;

use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ApplicationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'folio' => ['sometimes', 'string', 'max:40'],
            'status' => ['sometimes', Rule::enum(ApplicationStatus::class)],
            'result' => ['sometimes', Rule::in([
                ApplicationStatus::TERMINATED_UNFAVORABLE->value,
                ApplicationStatus::REJECTED->value,
                ApplicationStatus::ACTIVE->value,
            ])],
            'branch_id' => ['sometimes', 'uuid'],
            'coordinator_id' => ['sometimes', 'uuid'],
            'verifier_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['sometimes', Rule::in(['created_at', 'folio', 'status'])],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
