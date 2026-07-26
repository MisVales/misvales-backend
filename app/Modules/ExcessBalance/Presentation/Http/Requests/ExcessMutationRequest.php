<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class ExcessMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return [
            ...parent::validationData(),
            'idempotency_key' => $this->header('Idempotency-Key'),
        ];
    }

    /** @return array<string, mixed> */
    protected function baseRules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:200'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
