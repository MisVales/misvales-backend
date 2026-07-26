<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MobilityIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'folio' => ['sometimes', 'string', 'max:40'],
            'status' => ['sometimes', 'string', 'max:50'],
            'client_id' => ['sometimes', 'uuid'],
            'distributor_id' => ['sometimes', 'uuid'],
            'origin_distributor_id' => ['sometimes', 'uuid'],
            'recipient_distributor_id' => ['sometimes', 'uuid'],
            'origin_branch_id' => ['sometimes', 'integer', 'min:1'],
            'destination_branch_id' => ['sometimes', 'integer', 'min:1'],
            'branch_id' => ['sometimes', 'integer', 'min:1'],
            'origin_coordinator_id' => ['sometimes', 'integer', 'min:1'],
            'destination_coordinator_id' => ['sometimes', 'integer', 'min:1'],
            'outgoing_coordinator_id' => ['sometimes', 'integer', 'min:1'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
