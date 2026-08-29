<?php

namespace App\Http\Requests\VerificacionDistribuidora;

use App\Exceptions\ApiException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjuntarEvidenciaRequest extends FormRequest
{
    private const EVIDENCE_TYPES = [
        'FACHADA',
        'INTERIOR',
        'DOCUMENTO',
        'RESIDENCE_EXTERIOR',
        'RESIDENCE_INTERIOR',
        'IDENTIFICATION',
        'ADDRESS_PROOF',
        'VEHICLE_EVIDENCE',
        'ASSET_EVIDENCE',
        'COMMERCIAL_EVIDENCE',
        'OTHER',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:jpg,jpeg,jfif,png,webp,gif,bmp,tif,tiff,heic,heif,avif,pdf',
                'mimetypes:image/jpeg,image/jpg,image/pjpeg,image/jfif,image/png,image/x-png,image/webp,image/gif,image/bmp,image/x-bmp,image/x-ms-bmp,image/tiff,image/tif,image/x-tiff,image/heic,image/heif,image/avif,application/pdf',
                'max:10240',
            ],
            'file_type' => [
                'required',
                'string',
                'max:50',
                Rule::in(self::EVIDENCE_TYPES),
            ],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();
        if ($errors->has('file')) {
            $failed = $validator->failed()['file'] ?? [];
            if (isset($failed['Max'])) {
                throw new ApiException(
                    'VERIFICATION_EVIDENCE_TOO_LARGE',
                    'Archivo demasiado grande. El tamaño máximo es 10 MB.',
                    422,
                    ['file' => ['Archivo demasiado grande. El tamaño máximo es 10 MB.']],
                );
            }
            if (isset($failed['Mimes']) || isset($failed['Mimetypes'])) {
                throw new ApiException(
                    'VERIFICATION_EVIDENCE_MIME_INVALID',
                    'Archivo inválido. Solo se aceptan imágenes JPG, JPEG, JFIF, PNG, WebP, GIF, BMP, TIF, TIFF, HEIC, HEIF, AVIF o PDF.',
                    422,
                    ['file' => ['Archivo inválido. Solo se aceptan imágenes JPG, JPEG, JFIF, PNG, WebP, GIF, BMP, TIF, TIFF, HEIC, HEIF, AVIF o PDF.']],
                );
            }
            throw new ApiException(
                'VERIFICATION_EVIDENCE_TYPE_INVALID',
                'Selecciona un archivo válido de imagen o PDF.',
                422,
                ['file' => ['Selecciona un archivo válido de imagen o PDF.']],
            );
        }
        if ($errors->has('file_type')) {
            throw new ApiException(
                'VERIFICATION_EVIDENCE_TYPE_INVALID',
                'El tipo de evidencia no es válido. Selecciona una opción del catálogo.',
                422,
                ['file_type' => ['El tipo de evidencia no es válido. Selecciona una opción del catálogo.']],
            );
        }

        parent::failedValidation($validator);
    }
}
