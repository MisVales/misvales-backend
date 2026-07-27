<?php

namespace App\Modules\Distributor\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorización manejada en policy
    }

    public function rules(): array
    {
        return [
            'category_version_id' => 'required|uuid',
            'reason' => 'required|string|max:255',
            'lock_version' => 'required|integer',
        ];
    }
}
