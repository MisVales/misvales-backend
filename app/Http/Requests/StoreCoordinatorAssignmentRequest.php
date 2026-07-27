<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCoordinatorAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; 
    }

    public function rules(): array
    {
        return [
            'distributor_public_id' => ['required', 'uuid', 'exists:users,public_id'],
            'coordinator_public_id' => ['required', 'uuid', 'exists:users,public_id'],
            'branch_public_id'      => ['required', 'uuid', 'exists:branches,public_id'],
            'starts_at'             => ['required', 'date'],
            'ends_at'               => ['nullable', 'date', 'after:starts_at'],
            'reason'                => ['nullable', 'string', 'max:255']
        ];
    }
}