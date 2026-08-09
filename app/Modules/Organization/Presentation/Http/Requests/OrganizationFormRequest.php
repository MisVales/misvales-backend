<?php

namespace App\Modules\Organization\Presentation\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class OrganizationFormRequest extends FormRequest
{
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'code' => 'VALIDATION_ERROR',
            'message' => 'La solicitud contiene datos inválidos.',
            'fields' => $validator->errors(),
            'details' => (object) [],
            'request_id' => $this->attributes->get('request_id'),
        ], 422));
    }
}
