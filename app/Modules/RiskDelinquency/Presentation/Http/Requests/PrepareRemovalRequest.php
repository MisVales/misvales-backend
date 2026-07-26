<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PrepareRemovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['sometimes', 'nullable', 'string', 'max:1000']];
    }
}
