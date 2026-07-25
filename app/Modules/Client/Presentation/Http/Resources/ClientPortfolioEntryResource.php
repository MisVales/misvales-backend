<?php

declare(strict_types=1);

namespace App\Modules\Client\Presentation\Http\Resources;

use App\Modules\Client\Persistence\Models\ClientPortfolioEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Contrato público de una entrada informativa, sin campos de control internos. */
final class ClientPortfolioEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ClientPortfolioEntry $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            'entry_type' => $entry->entry_type->value,
            'amount' => $entry->amount === null ? null : bcadd($entry->amount, '0.005', 2),
            'informational_status' => $entry->informational_status->value,
            'occurred_on' => $entry->occurred_on->format('Y-m-d'),
            'note' => $entry->note,
            'lock_version' => $entry->lock_version,
            'created_at' => $entry->created_at->toIso8601String(),
        ];
    }
}
