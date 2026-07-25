<?php

declare(strict_types=1);

namespace App\Modules\Client\Presentation\Http\Resources;

use App\Modules\Client\Application\Contracts\ClientCashierVerificationData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Recurso mínimo para una cajera ya autorizada por vale y sucursal. */
final class ClientCashierVerificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ClientCashierVerificationData $client */
        $client = $this->resource;

        return [
            'id' => $client->clientId,
            'display_name' => $client->displayName,
            'address' => $client->address,
            'bank_account' => $client->bankAccount,
            'documents' => $client->documents,
        ];
    }
}
