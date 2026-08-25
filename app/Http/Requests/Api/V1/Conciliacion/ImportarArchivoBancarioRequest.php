<?php

namespace App\Http\Requests\Api\V1\Conciliacion;

use Illuminate\Foundation\Http\FormRequest;

final class ImportarArchivoBancarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermissionTo('bank_imports.create_branch') ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
            'process_run_id' => ['required', 'uuid', 'exists:relation_process_runs,id'],
        ];
    }
}
