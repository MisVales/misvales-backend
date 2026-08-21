<?php

namespace App\Http\Requests\Api\V1\Conciliacion;

use Illuminate\Foundation\Http\FormRequest;

final class SolicitarConciliacionManualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('manual_reconciliation.request_branch') ?? false;
    }

    public function rules(): array
    {
        return [
            'relation_id' => ['required', 'uuid', 'exists:distributor_relations,id'],
            'clarification_id' => ['required', 'uuid', 'exists:payment_clarifications,id'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
