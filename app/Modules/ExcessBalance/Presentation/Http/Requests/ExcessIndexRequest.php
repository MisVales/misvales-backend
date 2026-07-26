<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Presentation\Http\Requests;

use App\Modules\Payment\Domain\Enums\ExcessBalanceStatus;
use App\Modules\Payment\Domain\Enums\RefundRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ExcessIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $status = $this->routeIs('api.v1.me.refund-requests.*', 'api.v1.refund-requests.*')
            ? Rule::enum(RefundRequestStatus::class)
            : Rule::enum(ExcessBalanceStatus::class);
        $ownRoute = $this->routeIs('api.v1.me.*');

        return [
            'folio' => ['sometimes', 'string', 'max:40'],
            'distributor_id' => [$ownRoute ? 'prohibited' : 'sometimes', 'uuid'],
            'branch_id' => [$ownRoute ? 'prohibited' : 'sometimes', 'uuid'],
            'status' => ['sometimes', $status],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'has_retained' => ['sometimes', 'boolean'],
            'has_available' => ['sometimes', 'boolean'],
            'has_reservation' => ['sometimes', 'boolean'],
            'refund_pending' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $validated = $this->validated();
        foreach (['has_retained', 'has_available', 'has_reservation', 'refund_pending'] as $field) {
            if (array_key_exists($field, $validated)) {
                $validated[$field] = $this->boolean($field);
            }
        }

        return $validated;
    }
}
