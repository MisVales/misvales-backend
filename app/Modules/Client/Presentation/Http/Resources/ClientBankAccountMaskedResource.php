<?php

declare(strict_types=1);

namespace App\Modules\Client\Presentation\Http\Resources;

use App\Modules\Client\Persistence\Models\ClientBankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Presenta una cuenta exclusivamente de forma enmascarada. */
final class ClientBankAccountMaskedResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ClientBankAccount $account */
        $account = $this->resource;

        return [
            'id' => $account->id,
            'account_masked' => '********'.$account->account_last4,
            'effective_from' => $account->effective_from->toIso8601String(),
            'effective_to' => $account->effective_to?->toIso8601String(),
            'is_current' => (bool) $account->active_slot,
        ];
    }
}
