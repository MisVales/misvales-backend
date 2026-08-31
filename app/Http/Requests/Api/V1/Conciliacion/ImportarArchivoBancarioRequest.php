<?php

namespace App\Http\Requests\Api\V1\Conciliacion;

use Illuminate\Foundation\Http\FormRequest;

final class ImportarArchivoBancarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return ($user?->hasPermissionTo('bank_imports.create_branch') ?? false)
            && ($user?->hasRole('cashier') ?? false);
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
            'process_run_id' => ['required', 'uuid', 'exists:relation_process_runs,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.uploaded' => 'No fue posible cargar el archivo bancario. Intenta seleccionarlo nuevamente.',
            'file.required' => 'Selecciona el archivo bancario en formato XLSX.',
            'file.file' => 'El archivo bancario no es válido.',
            'file.mimes' => 'Archivo inválido. Solo se aceptan archivos Excel XLSX.',
            'file.max' => 'El archivo bancario no puede exceder 10 MB.',
            'process_run_id.required' => 'La corrida de conciliación es obligatoria.',
            'process_run_id.uuid' => 'La corrida de conciliación no es válida.',
            'process_run_id.exists' => 'La corrida de conciliación no existe.',
        ];
    }
}
