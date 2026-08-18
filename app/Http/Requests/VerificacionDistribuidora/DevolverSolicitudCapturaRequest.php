<?php

namespace App\Http\Requests\VerificacionDistribuidora;

use Illuminate\Foundation\Http\FormRequest;

class DevolverSolicitudCapturaRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'reason' => 'required|string|max:1000',
            'pending_sections' => 'required|array|min:1',
            'pending_sections.*' => 'required|string|max:50',
            'lock_version' => 'required|integer|min:1',
        ];
    }
}
