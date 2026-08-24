<?php

namespace App\Http\Requests\Api\V1\Conciliacion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CrearTransferenciaBancariaSimuladaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user?->hasPermissionTo('bank_imports.create_branch')
            || $user?->hasPermissionTo('relations.view_own')
            || false;
    }

    public function rules(): array
    {
        $paymentTypes = $this->user()?->hasRole('cashier')
            ? ['COUNTER']
            : ['TRANSFER', 'ONLINE_BANKING'];

        return [
            'relation_id' => ['required', 'uuid', 'exists:distributor_relations,id'],
            'amount' => ['required', 'decimal:0,4', 'gt:0'],
            'payment_type' => ['required', Rule::in($paymentTypes)],
            'concept' => ['nullable', 'string', 'max:500'],
            'paid_at' => ['nullable', 'date'],
        ];
    }
}
