<?php
namespace App\Http\Requests\VerificacionDistribuidora;
use Illuminate\Foundation\Http\FormRequest;

class ActualizarVisitaRequest extends FormRequest {
    public function authorize() { return true; }
    public function rules() {
        return [
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'accuracy' => 'nullable|numeric|min:0',
            'lock_version' => 'required|integer|min:1'
        ];
    }
}
