<?php

declare(strict_types=1);

namespace App\Modules\Payment\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Base de validación para mutaciones idempotentes y versionadas de M11. */
abstract class PaymentMutationRequest extends FormRequest
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

    /** @return array<string, list<string>> */
    protected function operationRules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:200'],
        ];
    }
}
