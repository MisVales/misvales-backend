<?php

namespace App\Http\Requests\Api\V1\Conciliacion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListarFlujoConciliacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return collect([
            'payment_clarifications.view_own',
            'payment_clarifications.view_assigned',
            'payment_clarifications.view_branch',
            'payment_clarifications.view_global',
            'manual_reconciliation.view_assigned',
            'manual_reconciliation.view_branch',
            'manual_reconciliation.view_global',
        ])->contains(fn (string $permission): bool => $this->user()?->hasPermissionTo($permission) ?? false);
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(['OPEN', 'IN_REVIEW', 'RESOLVED', 'REJECTED', 'REQUESTED', 'AUTHORIZED', 'EXECUTED'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
