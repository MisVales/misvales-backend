<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\AuthorizedChanges;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Client\Application\Contracts\ApplyAuthorizedClientChanges;
use App\Modules\Client\Application\Contracts\ApplyAuthorizedClientChangesCommand;
use App\Modules\Client\Application\Contracts\AuthorizedChangePort;
use App\Modules\Client\Application\Contracts\AuthorizedClientChange;
use App\Modules\Client\Application\Contracts\ClientAuditPort;
use App\Modules\Client\Application\Contracts\ClientChangeResult;
use App\Modules\Client\Application\Contracts\ClientOutboxPort;
use App\Modules\Client\Application\Contracts\DistributorProfilePort;
use App\Modules\Client\Application\Contracts\DocumentReferencePort;
use App\Modules\Client\Domain\Addresses\AddressNormalizer;
use App\Modules\Client\Domain\Clients\CurpNormalizer;
use App\Modules\Client\Domain\Documents\ClientDocumentType;
use App\Modules\Client\Domain\Exceptions\ClientDomainException;
use App\Modules\Client\Domain\Security\ExactMatchHmac;
use App\Modules\Client\Domain\Security\SensitiveDataProtector;
use App\Modules\Client\Persistence\Models\Client;
use App\Modules\Client\Persistence\Models\ClientAddress;
use App\Modules\Client\Persistence\Models\ClientBankAccount;
use App\Modules\Client\Persistence\Models\ClientDistributorAssignment;
use App\Modules\Client\Persistence\Models\ClientDocument;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Aplica solo campos autorizados y crea versiones sin sobrescribir historia. */
final readonly class ApplyAuthorizedChanges implements ApplyAuthorizedClientChanges
{
    private const ALLOWED_FIELDS = [
        'given_names',
        'surnames',
        'curp',
        'rfc',
        'birth_date',
        'birth_place',
        'birth_state',
        'birth_city',
        'address',
        'official_identification_media_id',
        'address_proof_media_id',
        'bank_account',
    ];

    public function __construct(
        private AuthorizedChangePort $authorizations,
        private DistributorProfilePort $profiles,
        private DocumentReferencePort $documents,
        private CurpNormalizer $curpNormalizer,
        private AddressNormalizer $addressNormalizer,
        private ExactMatchHmac $hmac,
        private SensitiveDataProtector $protector,
        private ClientAuditPort $audit,
        private ClientOutboxPort $outbox,
    ) {}

    public function handle(ApplyAuthorizedClientChangesCommand $command): ClientChangeResult
    {
        $this->assertCommand($command);
        $requestHash = $this->requestHash($command);

        try {
            return DB::transaction(function () use ($command, $requestHash): ClientChangeResult {
                $history = DB::table('client_change_history')->where('operation_id', $command->operationId)->first();
                if ($history !== null) {
                    if (
                        (string) $history->client_id !== $command->clientId
                        || (string) $history->authorization_id !== $command->authorizationId
                        || ! hash_equals((string) $history->request_hash, $requestHash)
                    ) {
                        throw ClientDomainException::idempotencyConflict();
                    }

                    /** @var list<string> $fields */
                    $fields = json_decode((string) $history->changed_fields, true, 512, JSON_THROW_ON_ERROR);
                    $version = (int) Client::query()->whereKey($command->clientId)->value('lock_version');

                    return new ClientChangeResult($command->clientId, $version, $fields, true);
                }

                $client = Client::query()->whereKey($command->clientId)->lockForUpdate()->first();
                if ($client === null) {
                    throw ClientDomainException::notFoundOrOutOfScope();
                }
                if ($client->lock_version !== $command->expectedClientVersion) {
                    throw ClientDomainException::versionConflict();
                }
                $assignment = ClientDistributorAssignment::query()
                    ->where('client_id', $client->id)
                    ->where('active_slot', true)
                    ->lockForUpdate()
                    ->first();
                if ($assignment === null) {
                    throw ClientDomainException::changeAuthorizationInvalid();
                }
                $profile = $this->profiles->activeById($assignment->distributor_id);
                if ($profile->branchId !== $command->cashier->branchId) {
                    throw ClientDomainException::changeAuthorizationInvalid();
                }
                $authorization = $this->authorizations->consume(new AuthorizedClientChange(
                    authorizationId: $command->authorizationId,
                    clientId: $command->clientId,
                    fields: $command->authorizedFields,
                    cashierUserId: $command->cashier->userId,
                    branchId: (int) $command->cashier->branchId,
                    operationId: $command->operationId,
                ));

                $previous = [];
                $new = [];
                $events = [];
                foreach ($command->authorizedFields as $field) {
                    [$previous[$field], $new[$field], $event] = $this->applyField($client, $field, $command);
                    if ($event !== null) {
                        $events[$event] = true;
                    }
                }

                $client->forceFill(['lock_version' => $client->lock_version + 1])->save();
                DB::table('client_change_history')->insert([
                    'id' => (string) Str::uuid(),
                    'client_id' => $client->id,
                    'authorization_id' => $command->authorizationId,
                    'operation_id' => $command->operationId,
                    'request_hash' => $requestHash,
                    'changed_fields' => json_encode($command->authorizedFields, JSON_THROW_ON_ERROR),
                    'protected_previous_values' => $this->protector->encrypt(json_encode($previous, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                    'protected_new_values' => $this->protector->encrypt(json_encode($new, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
                    'reason' => $command->reason,
                    'requested_by' => $authorization->requestedBy,
                    'authorized_by' => $authorization->authorizedBy,
                    'executed_by' => $command->cashier->userId,
                    'branch_id' => $command->cashier->branchId,
                    'changed_at' => now(),
                ]);

                $this->audit->record(
                    'CLIENT_DATA_CHANGED_WITH_AUTHORIZATION',
                    $client->id,
                    $command->cashier,
                    null,
                    $command->operationId,
                    $command->authorizedFields,
                    'SUCCESS',
                    $command->requestId,
                    $command->reason,
                    requestedBy: $authorization->requestedBy,
                    authorizedBy: $authorization->authorizedBy,
                );
                $this->outbox->append('ClientDataChangedWithAuthorization', [
                    'client_id' => $client->id,
                    'operation_id' => $command->operationId,
                    'changed_fields' => implode(',', $command->authorizedFields),
                    'changed_at' => now()->toIso8601String(),
                ], 'client-authorized-change:'.$command->operationId);
                foreach (array_keys($events) as $event) {
                    $this->outbox->append($event, [
                        'client_id' => $client->id,
                        'operation_id' => $command->operationId,
                        'changed_at' => now()->toIso8601String(),
                    ], strtolower($event).':'.$command->operationId);
                }

                return new ClientChangeResult(
                    $client->id,
                    $client->lock_version,
                    $command->authorizedFields,
                    false,
                );
            }, 3);
        } catch (QueryException $exception) {
            $message = mb_strtolower($exception->getMessage());
            if (str_contains($message, 'curp_hmac')) {
                throw ClientDomainException::curpExists();
            }
            if (str_contains($message, 'address_fingerprint_hmac')) {
                throw ClientDomainException::addressExists();
            }

            throw $exception;
        }
    }

    private function assertCommand(ApplyAuthorizedClientChangesCommand $command): void
    {
        if (
            $command->cashier->role !== RoleCode::CASHIER
            || $command->cashier->branchId === null
            || ! $command->cashier->hasPermission(PermissionCode::CLIENTS_APPLY_AUTHORIZED_CHANGE->value)
        ) {
            throw ClientDomainException::authorizationDenied();
        }
        $fields = $command->authorizedFields;
        $valueFields = array_keys($command->newValues);
        sort($fields);
        sort($valueFields);
        if (
            $fields === []
            || $fields !== $valueFields
            || array_diff($fields, self::ALLOWED_FIELDS) !== []
        ) {
            throw ClientDomainException::changeAuthorizationInvalid();
        }
    }

    private function requestHash(ApplyAuthorizedClientChangesCommand $command): string
    {
        $fields = $command->authorizedFields;
        sort($fields);
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = $this->canonicalValue($command->newValues[$field]);
        }

        return hash('sha256', json_encode([
            'authorization_id' => $command->authorizationId,
            'client_id' => $command->clientId,
            'fields' => $fields,
            'new_values' => $values,
            'reason' => trim($command->reason),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return is_string($value) ? trim($value) : $value;
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalValue($item);
        }

        return $value;
    }

    /** @return array{mixed, mixed, ?string} */
    private function applyField(Client $client, string $field, ApplyAuthorizedClientChangesCommand $command): array
    {
        $value = $command->newValues[$field];

        return match ($field) {
            'given_names' => $this->plainField($client, 'given_names', $value, 160),
            'surnames' => $this->plainField($client, 'surnames', $value, 200),
            'curp' => $this->curpField($client, $value),
            'rfc' => $this->encryptedField($client, 'rfc_ciphertext', $value, 30),
            'birth_date' => $this->birthDateField($client, $value),
            'birth_place' => $this->encryptedField($client, 'birth_place_ciphertext', $value, 200),
            'birth_state' => $this->encryptedField($client, 'birth_state_ciphertext', $value, 120),
            'birth_city' => $this->encryptedField($client, 'birth_city_ciphertext', $value, 160),
            'address' => $this->addressField($client, $value, $command),
            'bank_account' => $this->bankField($client, $value, $command),
            'official_identification_media_id' => $this->documentField(
                $client,
                $value,
                ClientDocumentType::OFFICIAL_IDENTIFICATION,
                $command,
            ),
            'address_proof_media_id' => $this->documentField(
                $client,
                $value,
                ClientDocumentType::ADDRESS_PROOF,
                $command,
            ),
            default => throw ClientDomainException::changeAuthorizationInvalid(),
        };
    }

    /** @return array{mixed, mixed, null} */
    private function plainField(Client $client, string $attribute, mixed $value, int $maximum): array
    {
        if (! is_string($value) || trim($value) === '') {
            throw ClientDomainException::changeAuthorizationInvalid();
        }
        $previous = $client->getAttribute($attribute);
        $withoutMarkup = strip_tags($value);
        $new = preg_replace('/\s+/u', ' ', trim($withoutMarkup)) ?? trim($withoutMarkup);
        if ($new === '' || mb_strlen($new) > $maximum) {
            throw ClientDomainException::dataIncomplete($attribute);
        }
        $client->forceFill([$attribute => $new])->save();

        return [$previous, $new, null];
    }

    /** @return array{?string, ?string, null} */
    private function encryptedField(Client $client, string $attribute, mixed $value, int $maximum): array
    {
        if ($value !== null && ! is_string($value)) {
            throw ClientDomainException::changeAuthorizationInvalid();
        }
        $previousCiphertext = $client->getAttribute($attribute);
        $previous = is_string($previousCiphertext) ? $this->protector->decrypt($previousCiphertext) : null;
        $new = $value === null || trim($value) === '' ? null : trim($value);
        if ($new !== null && mb_strlen($new) > $maximum) {
            throw ClientDomainException::dataIncomplete($attribute);
        }
        $client->forceFill([$attribute => $new === null ? null : $this->protector->encrypt($new)])->save();

        return [$previous, $new, null];
    }

    /** @return array{mixed, string, null} */
    private function birthDateField(Client $client, mixed $value): array
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) !== 1) {
            throw ClientDomainException::changeAuthorizationInvalid();
        }
        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || $value > now('UTC')->toDateString()) {
            throw ClientDomainException::dataIncomplete('birth_date');
        }
        $previous = $client->birth_date;
        $client->forceFill(['birth_date' => $value])->save();

        return [$previous, $value, null];
    }

    /** @return array{string, string, null} */
    private function curpField(Client $client, mixed $value): array
    {
        if (! is_string($value)) {
            throw ClientDomainException::changeAuthorizationInvalid();
        }
        $new = $this->curpNormalizer->normalize($value);
        $hmac = $this->hmac->make($new);
        if (Client::query()->where('curp_hmac', $hmac)->whereKeyNot($client->id)->exists()) {
            throw ClientDomainException::curpExists();
        }
        $previous = $this->protector->decrypt($client->curp_ciphertext);
        $client->forceFill([
            'curp_ciphertext' => $this->protector->encrypt($new),
            'curp_hmac' => $hmac,
            'curp_last4' => substr($new, -4),
        ])->save();

        return [$previous, $new, null];
    }

    /** @return array{array<string, string|null>, array<string, string|null>, string} */
    private function addressField(Client $client, mixed $value, ApplyAuthorizedClientChangesCommand $command): array
    {
        if (! is_array($value)) {
            throw ClientDomainException::changeAuthorizationInvalid();
        }
        /** @var array{street:string,exterior_number:string,interior_number?:?string,neighborhood:string,postal_code:string,municipality:string,city:string,state:string} $value */
        $normalized = $this->addressNormalizer->normalize($value);
        $fingerprint = $this->hmac->make($normalized->fingerprintInput());
        if (ClientAddress::query()
            ->where('address_fingerprint_hmac', $fingerprint)
            ->where('active_slot', true)
            ->where('client_id', '!=', $client->id)
            ->exists()) {
            throw ClientDomainException::addressExists();
        }
        $current = ClientAddress::query()
            ->where('client_id', $client->id)
            ->where('active_slot', true)
            ->lockForUpdate()
            ->firstOrFail();
        $previous = $this->decryptedAddress($current);
        $current->forceFill(['effective_to' => now(), 'active_slot' => null])->save();

        $next = new ClientAddress;
        $next->forceFill([
            'id' => (string) Str::uuid(),
            'client_id' => $client->id,
            ...$this->encryptedAddress($normalized->display),
            'address_fingerprint_hmac' => $fingerprint,
            'normalization_version' => $normalized->version,
            'effective_from' => now(),
            'effective_to' => null,
            'change_authorization_id' => $command->authorizationId,
            'created_by' => $command->cashier->userId,
            'active_slot' => true,
        ])->save();

        return [$previous, $normalized->display, 'ClientAddressChanged'];
    }

    /** @return array{?string, string, string} */
    private function bankField(Client $client, mixed $value, ApplyAuthorizedClientChangesCommand $command): array
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > 160) {
            throw ClientDomainException::changeAuthorizationInvalid();
        }
        $new = trim($value);
        $current = ClientBankAccount::query()
            ->where('client_id', $client->id)
            ->where('active_slot', true)
            ->lockForUpdate()
            ->first();
        $previous = $current === null ? null : $this->protector->decrypt($current->account_ciphertext);
        $current?->forceFill(['effective_to' => now(), 'active_slot' => null])->save();

        $next = new ClientBankAccount;
        $next->forceFill([
            'id' => (string) Str::uuid(),
            'client_id' => $client->id,
            'account_ciphertext' => $this->protector->encrypt($new),
            'account_hmac' => $this->hmac->make(mb_strtoupper($new, 'UTF-8')),
            'account_last4' => mb_substr($new, -4),
            'effective_from' => now(),
            'effective_to' => null,
            'change_authorization_id' => $command->authorizationId,
            'created_by' => $command->cashier->userId,
            'active_slot' => true,
        ])->save();

        return [$previous, $new, 'ClientBankAccountChanged'];
    }

    /** @return array{?string, string, null} */
    private function documentField(
        Client $client,
        mixed $value,
        ClientDocumentType $type,
        ApplyAuthorizedClientChangesCommand $command,
    ): array {
        if (! is_string($value)) {
            throw ClientDomainException::changeAuthorizationInvalid();
        }
        $reference = $this->documents->assertAvailableToActor($value, $command->cashier->userId);
        $current = ClientDocument::query()
            ->where('client_id', $client->id)
            ->where('document_type', $type->value)
            ->where('active_slot', true)
            ->lockForUpdate()
            ->first();
        $previous = $current?->media_id;
        if ($current !== null) {
            $current->forceFill(['effective_to' => now(), 'active_slot' => null])->save();
        }

        $next = new ClientDocument;
        $next->forceFill([
            'id' => (string) Str::uuid(),
            'client_id' => $client->id,
            'document_type' => $type,
            'media_id' => $reference->mediaId,
            'file_fingerprint' => $reference->fingerprint,
            'effective_from' => now(),
            'effective_to' => null,
            'replaced_document_id' => $current?->id,
            'created_by' => $command->cashier->userId,
            'active_slot' => true,
        ])->save();

        return [$previous, $reference->mediaId, null];
    }

    /** @return array<string, string|null> */
    private function decryptedAddress(ClientAddress $address): array
    {
        return [
            'street' => $this->protector->decrypt($address->street_ciphertext),
            'exterior_number' => $this->protector->decrypt($address->exterior_number_ciphertext),
            'interior_number' => $address->interior_number_ciphertext === null ? null : $this->protector->decrypt($address->interior_number_ciphertext),
            'neighborhood' => $this->protector->decrypt($address->neighborhood_ciphertext),
            'postal_code' => $this->protector->decrypt($address->postal_code_ciphertext),
            'municipality' => $this->protector->decrypt($address->municipality_ciphertext),
            'city' => $this->protector->decrypt($address->city_ciphertext),
            'state' => $this->protector->decrypt($address->state_ciphertext),
        ];
    }

    /**
     * @param  array<string, string|null>  $address
     * @return array<string, string|null>
     */
    private function encryptedAddress(array $address): array
    {
        return [
            'street_ciphertext' => $this->protector->encrypt((string) $address['street']),
            'exterior_number_ciphertext' => $this->protector->encrypt((string) $address['exterior_number']),
            'interior_number_ciphertext' => $address['interior_number'] === null ? null : $this->protector->encrypt($address['interior_number']),
            'neighborhood_ciphertext' => $this->protector->encrypt((string) $address['neighborhood']),
            'postal_code_ciphertext' => $this->protector->encrypt((string) $address['postal_code']),
            'municipality_ciphertext' => $this->protector->encrypt((string) $address['municipality']),
            'city_ciphertext' => $this->protector->encrypt((string) $address['city']),
            'state_ciphertext' => $this->protector->encrypt((string) $address['state']),
        ];
    }
}
