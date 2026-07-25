<?php

declare(strict_types=1);

namespace App\Modules\Client\Presentation\Http\Resources;

use App\Modules\Client\Application\Registration\ClientRegistrationResult;
use App\Modules\Client\Persistence\Models\Client;
use App\Modules\Client\Persistence\Models\ClientPortfolioSetting;
use App\Modules\Client\Presentation\Http\Resources\Concerns\FormatsProtectedClientData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Detalle del cliente visible únicamente para su distribuidora vigente. */
final class ClientDistributorDetailResource extends JsonResource
{
    use FormatsProtectedClientData;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof ClientRegistrationResult) {
            $result = $this->resource;

            return [
                'id' => $result->client->id,
                'display_name' => trim($result->client->given_names.' '.$result->client->surnames),
                'curp_masked' => $this->mask($result->client->curp_last4),
                'distributor' => [
                    'id' => $result->distributor->distributorId,
                    'number' => $result->distributor->number,
                ],
                'branch' => [
                    'id' => $result->distributor->branchPublicId,
                    'name' => $result->distributor->branchName,
                ],
                'registered_at' => $this->displayDate($result->client->created_at),
                'existing_for_future_vouchers' => true,
                'replayed' => $result->replayed,
            ];
        }

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
            'rfc_masked' => $client->rfc_ciphertext === null
                ? null
                : $this->maskedProtected($client->rfc_ciphertext),
            'birth_date' => $client->birth_date?->format('Y-m-d'),
            'address' => $this->address($client->currentAddress),
            'distributor_id' => $profile->distributorId,
            'branch' => [
                'id' => $profile->branchPublicId,
                'name' => $profile->branchName,
            ],
            'bank_account' => $client->currentBankAccount === null
                ? null
                : '********'.$client->currentBankAccount->account_last4,
            'documents' => $client->currentDocuments->map(static fn ($document): array => [
                'id' => $document->id,
                'type' => $document->document_type->value,
                'private_reference' => $document->media_id,
            ])->values(),
            'portfolio_tracking_enabled' => $setting->tracking_enabled,
            'portfolio_version' => $setting->lock_version,
            'lock_version' => $client->lock_version,
            'registered_at' => $this->displayDate($client->created_at),
        ];
    }

    private function maskedProtected(string $ciphertext): string
    {
        $value = $this->protector()->decrypt($ciphertext);

        return $this->mask(mb_substr($value, -4), max(mb_strlen($value), 8));
    }
}
