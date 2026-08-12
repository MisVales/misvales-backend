<?php

namespace App\Http\Requests\Api\V1\Vale;

use App\Models\Vale;
use Illuminate\Foundation\Http\FormRequest;

final class PrevisualizarValeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Vale::class) ?? false;
    }

    public function rules(): array
    {
        return ['client_id' => ['required', 'uuid'], 'product_version_id' => ['required', 'uuid']];
    }
}
