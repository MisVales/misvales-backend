<?php

declare(strict_types=1);

namespace App\Modules\Points\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PointDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'reauthentication_token' => ['required', 'string', 'size:64'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
