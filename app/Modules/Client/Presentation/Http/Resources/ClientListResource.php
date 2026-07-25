<?php

declare(strict_types=1);

namespace App\Modules\Client\Presentation\Http\Resources;

use App\Modules\Client\Persistence\Models\Client;
use App\Modules\Client\Persistence\Models\ClientPortfolioSetting;
use App\Modules\Client\Presentation\Http\Resources\Concerns\FormatsProtectedClientData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Fila reducida para listados; evita descifrar PII. */
final class ClientListResource extends JsonResource
{
    use FormatsProtectedClientData;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Client $client */
        $client = $this->resource;
        $assignment = $client->currentAssignment;
        $profile = $client->resolved_distributor_profile;
        $setting = $client->portfolioSettings->first(
            static fn (ClientPortfolioSetting $item): bool => $item->assignment_id === $assignment?->id,
        );

        return [
            'id' => $client->id,
            'display_name' => trim($client->given_names.' '.$client->surnames),
            'curp_masked' => $this->mask($client->curp_last4),
            'distributor_id' => $profile->distributorId,
            'branch' => [
                'id' => $profile->branchPublicId,
                'name' => $profile->branchName,
            ],
            'portfolio_tracking_enabled' => $setting->tracking_enabled,
            'registered_at' => $this->displayDate($client->created_at),
        ];
    }
}
