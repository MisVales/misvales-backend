<?php

namespace App\Http\Requests\Api\V1\Cliente;

use App\Exceptions\ApiException;
use App\Models\Cliente;
use App\Models\ClientRegistrationDraft;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class CompletarBorradorClienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $draft = $this->route('draft');

        return $draft instanceof ClientRegistrationDraft
            && ($this->user()?->can('create', Cliente::class) ?? false);
    }

    public function rules(): array
    {
        return [];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ApiException('CLIENT_REGISTRATION_DRAFT_INVALID', 'No fue posible completar el registro del cliente.', 422, $validator->errors()->toArray(), []);
    }
}
