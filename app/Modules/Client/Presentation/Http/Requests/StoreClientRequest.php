<?php

declare(strict_types=1);

namespace App\Modules\Client\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Rechaza campos de autoridad y valida la forma completa del alta. */
final class StoreClientRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'given_names' => ['required', 'string', 'max:160'],
            'surnames' => ['required', 'string', 'max:200'],
            'curp' => ['required', 'string', 'max:30'],
            'rfc' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'birth_place' => ['nullable', 'string', 'max:200'],
            'birth_state' => ['nullable', 'string', 'max:120'],
            'birth_city' => ['nullable', 'string', 'max:160'],
            'address' => ['required', 'array:street,exterior_number,interior_number,neighborhood,postal_code,municipality,city,state'],
            'address.street' => ['required', 'string', 'max:200'],
            'address.exterior_number' => ['required', 'string', 'max:40'],
            'address.interior_number' => ['nullable', 'string', 'max:40'],
            'address.neighborhood' => ['required', 'string', 'max:160'],
            'address.postal_code' => ['required', 'string', 'max:20'],
            'address.municipality' => ['required', 'string', 'max:160'],
            'address.city' => ['required', 'string', 'max:160'],
            'address.state' => ['required', 'string', 'max:120'],
            'official_identification_media_id' => ['required', 'uuid'],
            'address_proof_media_id' => ['required', 'uuid'],
            'bank_account' => ['required', 'string', 'max:160'],
            'idempotency_key' => ['required', 'string', 'max:150'],
            'user_id' => ['prohibited'],
            'distributor_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'actor' => ['prohibited'],
            'created_by' => ['prohibited'],
            'lock_version' => ['prohibited'],
            'delinquent' => ['prohibited'],
            'blocked' => ['prohibited'],
            'eligible' => ['prohibited'],
            'risk_status' => ['prohibited'],
            'balance' => ['prohibited'],
            'credit_line' => ['prohibited'],
        ];
    }
}
