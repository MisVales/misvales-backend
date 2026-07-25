<?php

declare(strict_types=1);

namespace App\Modules\Client\Presentation\Http\Resources;

use App\Models\User;
use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Client\Application\Security\ClientActorContextFactory;
use App\Modules\Client\Persistence\Models\Client;
use App\Modules\Client\Presentation\Http\Resources\Concerns\FormatsProtectedClientData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Detalle administrativo de solo lectura con PII enmascarada. */
final class ClientAdministrativeDetailResource extends JsonResource
{
    use FormatsProtectedClientData;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Client $client */
        $client = $this->resource;
        $assignment = $client->currentAssignment;
        $profile = $client->resolved_distributor_profile;
        /** @var User $user */
        $user = $request->user();
        $actor = app(ClientActorContextFactory::class)->fromUser($user);
        $canViewSensitive = $actor->hasPermission(PermissionCode::CLIENTS_VIEW_SENSITIVE_AUTHORIZED->value);
        $canViewDocuments = $actor->hasPermission(PermissionCode::CLIENTS_VIEW_DOCUMENTS_AUTHORIZED->value);

        return [
            'id' => $client->id,
            'display_name' => trim($client->given_names.' '.$client->surnames),
            'curp_masked' => $this->mask($client->curp_last4),
            'birth_date' => $canViewSensitive ? $client->birth_date?->format('Y-m-d') : null,
            'address' => $canViewSensitive ? $this->address($client->currentAddress) : null,
            'current_assignment' => $assignment === null ? null : [
                'distributor_id' => $assignment->distributor_id,
                'branch' => [
                    'id' => $profile->branchPublicId,
                    'name' => $profile->branchName,
                ],
                'effective_from' => $this->displayDate($assignment->effective_from),
            ],
            'assignment_history' => $client->assignmentHistory->map(fn ($item): array => [
                'id' => $item->id,
                'distributor_id' => $item->distributor_id,
                'branch_id_snapshot' => $item->branch?->public_id,
                'assignment_type' => $item->assignment_type->value,
                'effective_from' => $this->displayDate($item->effective_from),
                'effective_to' => $this->displayDate($item->effective_to),
            ])->values(),
            'bank_account' => $client->currentBankAccount === null
                ? null
                : '********'.$client->currentBankAccount->account_last4,
            'documents' => $canViewDocuments
                ? $client->currentDocuments->map(static fn ($document): array => [
                    'id' => $document->id,
                    'type' => $document->document_type->value,
                    'private_reference' => $document->media_id,
                ])->values()
                : [],
            'lock_version' => $client->lock_version,
            'registered_at' => $this->displayDate($client->created_at),
        ];
    }
}
