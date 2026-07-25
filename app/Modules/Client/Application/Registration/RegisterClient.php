<?php

declare(strict_types=1);

namespace App\Modules\Client\Application\Registration;

use App\Modules\Access\Domain\Authorization\PermissionCode;
use App\Modules\Access\Domain\Authorization\RoleCode;
use App\Modules\Client\Application\Contracts\ClientAuditPort;
use App\Modules\Client\Application\Contracts\ClientOutboxPort;
use App\Modules\Client\Application\Contracts\DistributorProfile;
use App\Modules\Client\Application\Contracts\DistributorProfilePort;
use App\Modules\Client\Application\Contracts\DocumentReferencePort;
use App\Modules\Client\Domain\Addresses\AddressNormalizer;
use App\Modules\Client\Domain\Assignments\AssignmentType;
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
use App\Modules\Client\Persistence\Models\ClientPortfolioSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Ejecuta el alta completa, atómica, globalmente única e idempotente. */
final readonly class RegisterClient
{
    public function __construct(
        private CurpNormalizer $curpNormalizer,
        private AddressNormalizer $addressNormalizer,
        private ExactMatchHmac $hmac,
        private SensitiveDataProtector $protector,
        private DistributorProfilePort $profiles,
        private DocumentReferencePort $documents,
        private ClientAuditPort $audit,
        private ClientOutboxPort $outbox,
    ) {}

    public function execute(RegisterClientCommand $command): ClientRegistrationResult
    {
        $this->assertAuthority($command);
        $profile = $this->profiles->forAuthenticatedDistributor($command->actor->userId);
        $curp = $this->curpNormalizer->normalize($command->curp);
        $address = $this->addressNormalizer->normalize($command->address->toArray());
        $curpHmac = $this->hmac->make($curp);
        $addressHmac = $this->hmac->make($address->fingerprintInput());
        $requestHash = $this->requestHash($command, $curp, $address->canonical);

        $existing = $this->replayed($command, $profile, $requestHash);
        if ($existing !== null) {
            return $existing;
        }

        $officialIdentification = $this->documents->assertAvailableToActor(
            $command->officialIdentificationMediaId,
            $command->actor->userId,
        );
        $addressProof = $this->documents->assertAvailableToActor(
            $command->addressProofMediaId,
            $command->actor->userId,
        );

        try {
            return DB::transaction(function () use (
                $command,
                $profile,
                $curp,
                $curpHmac,
                $address,
                $addressHmac,
                $officialIdentification,
                $addressProof,
                $requestHash,
            ): ClientRegistrationResult {
                DB::table('client_registration_idempotency')->insert([
                    'actor_user_id' => $command->actor->userId,
                    'idempotency_key' => $command->idempotencyKey,
                    'request_hash' => $requestHash,
                    'client_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if (Client::query()->where('curp_hmac', $curpHmac)->exists()) {
                    throw ClientDomainException::curpExists();
                }
                if (ClientAddress::query()->where('address_fingerprint_hmac', $addressHmac)->where('active_slot', true)->exists()) {
                    throw ClientDomainException::addressExists();
                }

                $now = now();
                $client = new Client;
                $client->forceFill([
                    'id' => (string) Str::uuid(),
                    'given_names' => $this->name($command->givenNames, 160),
                    'surnames' => $this->name($command->surnames, 200),
                    'curp_ciphertext' => $this->protector->encrypt($curp),
                    'curp_hmac' => $curpHmac,
                    'curp_last4' => substr($curp, -4),
                    'rfc_ciphertext' => $this->encryptNullable($this->optional($command->rfc, 30, 'rfc')),
                    'birth_date' => $this->birthDate($command->birthDate),
                    'birth_place_ciphertext' => $this->encryptNullable($this->optional($command->birthPlace, 200, 'birth_place')),
                    'birth_state_ciphertext' => $this->encryptNullable($this->optional($command->birthState, 120, 'birth_state')),
                    'birth_city_ciphertext' => $this->encryptNullable($this->optional($command->birthCity, 160, 'birth_city')),
                    'created_by' => $command->actor->userId,
                    'registration_operation_id' => hash('sha256', $command->actor->userId.'|'.$command->idempotencyKey),
                    'lock_version' => 1,
                ])->save();

                $addressModel = new ClientAddress;
                $addressModel->forceFill([
                    'id' => (string) Str::uuid(),
                    'client_id' => $client->id,
                    ...$this->protectedAddress($address->display),
                    'address_fingerprint_hmac' => $addressHmac,
                    'normalization_version' => $address->version,
                    'effective_from' => $now,
                    'effective_to' => null,
                    'change_authorization_id' => null,
                    'created_by' => $command->actor->userId,
                    'active_slot' => true,
                ])->save();

                $bankAccount = $this->bankAccount($command->bankAccount);
                $bank = new ClientBankAccount;
                $bank->forceFill([
                    'id' => (string) Str::uuid(),
                    'client_id' => $client->id,
                    'account_ciphertext' => $this->protector->encrypt($bankAccount),
                    'account_hmac' => $this->hmac->make(mb_strtoupper($bankAccount, 'UTF-8')),
                    'account_last4' => mb_substr($bankAccount, -4),
                    'effective_from' => $now,
                    'effective_to' => null,
                    'change_authorization_id' => null,
                    'created_by' => $command->actor->userId,
                    'active_slot' => true,
                ])->save();

                foreach ([
                    ClientDocumentType::OFFICIAL_IDENTIFICATION->value => $officialIdentification,
                    ClientDocumentType::ADDRESS_PROOF->value => $addressProof,
                ] as $type => $reference) {
                    $document = new ClientDocument;
                    $document->forceFill([
                        'id' => (string) Str::uuid(),
                        'client_id' => $client->id,
                        'document_type' => $type,
                        'media_id' => $reference->mediaId,
                        'file_fingerprint' => $reference->fingerprint,
                        'effective_from' => $now,
                        'effective_to' => null,
                        'replaced_document_id' => null,
                        'created_by' => $command->actor->userId,
                        'active_slot' => true,
                    ])->save();
                }

                $assignment = new ClientDistributorAssignment;
                $assignment->forceFill([
                    'id' => (string) Str::uuid(),
                    'client_id' => $client->id,
                    'distributor_id' => $profile->distributorId,
                    'branch_id_snapshot' => $profile->branchId,
                    'effective_from' => $now,
                    'effective_to' => null,
                    'assignment_type' => AssignmentType::INITIAL,
                    'mobility_operation_id' => null,
                    'reason' => null,
                    'changed_by' => $command->actor->userId,
                    'active_slot' => true,
                ])->save();

                $setting = new ClientPortfolioSetting;
                $setting->forceFill([
                    'id' => (string) Str::uuid(),
                    'client_id' => $client->id,
                    'distributor_id' => $profile->distributorId,
                    'assignment_id' => $assignment->id,
                    'tracking_enabled' => false,
                    'lock_version' => 1,
                    'updated_by' => $command->actor->userId,
                ])->save();

                DB::table('client_registration_idempotency')
                    ->where('actor_user_id', $command->actor->userId)
                    ->where('idempotency_key', $command->idempotencyKey)
                    ->update(['client_id' => $client->id, 'updated_at' => now()]);

                $this->audit->record(
                    'CLIENT_REGISTERED',
                    $client->id,
                    $command->actor,
                    $profile->distributorId,
                    null,
                    ['identity', 'address', 'bank_account', 'documents', 'assignment'],
                    'SUCCESS',
                    $command->requestId,
                );
                $this->outbox->append('ClientRegistered', [
                    'client_id' => $client->id,
                    'distributor_id' => $profile->distributorId,
                    'branch_id' => $profile->branchPublicId,
                    'registered_at' => $now->toIso8601String(),
                ], 'client-registered:'.$client->id);

                return new ClientRegistrationResult($client->refresh(), $profile, false);
            }, 3);
        } catch (ClientDomainException $exception) {
            if (in_array($exception->errorCode(), ['CLIENT_CURP_EXISTS', 'CLIENT_ADDRESS_EXISTS'], true)) {
                $this->audit->record(
                    $exception->errorCode(),
                    null,
                    $command->actor,
                    $profile->distributorId,
                    null,
                    [],
                    'REJECTED',
                    $command->requestId,
                );
            }

            throw $exception;
        } catch (QueryException $exception) {
            return $this->translateCollision($exception, $command, $profile, $requestHash);
        }
    }

    private function assertAuthority(RegisterClientCommand $command): void
    {
        if (
            $command->actor->role !== RoleCode::DISTRIBUTOR
            || ! $command->actor->hasPermission(PermissionCode::CLIENTS_CREATE_OWN->value)
        ) {
            throw ClientDomainException::authorizationDenied();
        }
    }

    private function replayed(
        RegisterClientCommand $command,
        DistributorProfile $profile,
        string $requestHash,
    ): ?ClientRegistrationResult {
        $record = DB::table('client_registration_idempotency')
            ->where('actor_user_id', $command->actor->userId)
            ->where('idempotency_key', $command->idempotencyKey)
            ->first();

        if ($record === null) {
            return null;
        }
        if (! hash_equals((string) $record->request_hash, $requestHash)) {
            throw ClientDomainException::idempotencyConflict();
        }
        if ($record->client_id === null) {
            throw ClientDomainException::versionConflict();
        }

        $client = Client::query()->findOrFail((string) $record->client_id);

        return new ClientRegistrationResult($client, $profile, true);
    }

    /**
     * @param  array<string, string>  $canonicalAddress
     */
    private function requestHash(RegisterClientCommand $command, string $curp, array $canonicalAddress): string
    {
        return hash('sha256', json_encode([
            'given_names' => $this->name($command->givenNames, 160),
            'surnames' => $this->name($command->surnames, 200),
            'curp' => $curp,
            'rfc' => ($rfc = $this->optional($command->rfc, 30, 'rfc')) === null
                ? null
                : mb_strtoupper($rfc, 'UTF-8'),
            'birth_date' => $this->birthDate($command->birthDate),
            'birth_place' => $this->optional($command->birthPlace, 200, 'birth_place'),
            'birth_state' => $this->optional($command->birthState, 120, 'birth_state'),
            'birth_city' => $this->optional($command->birthCity, 160, 'birth_city'),
            'address' => $canonicalAddress,
            'official_identification_media_id' => $command->officialIdentificationMediaId,
            'address_proof_media_id' => $command->addressProofMediaId,
            'bank_account' => $this->bankAccount($command->bankAccount),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, ?string>  $display
     * @return array<string, string|null>
     */
    private function protectedAddress(array $display): array
    {
        return [
            'street_ciphertext' => $this->protector->encrypt((string) $display['street']),
            'exterior_number_ciphertext' => $this->protector->encrypt((string) $display['exterior_number']),
            'interior_number_ciphertext' => $this->encryptNullable($display['interior_number']),
            'neighborhood_ciphertext' => $this->protector->encrypt((string) $display['neighborhood']),
            'postal_code_ciphertext' => $this->protector->encrypt((string) $display['postal_code']),
            'municipality_ciphertext' => $this->protector->encrypt((string) $display['municipality']),
            'city_ciphertext' => $this->protector->encrypt((string) $display['city']),
            'state_ciphertext' => $this->protector->encrypt((string) $display['state']),
        ];
    }

    private function encryptNullable(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $this->protector->encrypt(trim($value));
    }

    private function name(string $value, int $maximum): string
    {
        $withoutMarkup = strip_tags($value);
        $normalized = preg_replace('/\s+/u', ' ', trim($withoutMarkup)) ?? trim($withoutMarkup);
        if ($normalized === '' || mb_strlen($normalized) > $maximum) {
            throw ClientDomainException::dataIncomplete('name');
        }

        return $normalized;
    }

    private function optional(?string $value, int $maximum, string $field): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $normalized = trim(strip_tags($value));
        if ($normalized === '' || mb_strlen($normalized) > $maximum) {
            throw ClientDomainException::dataIncomplete($field);
        }

        return $normalized;
    }

    private function birthDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) !== 1) {
            throw ClientDomainException::dataIncomplete('birth_date');
        }
        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || $value > now('UTC')->toDateString()) {
            throw ClientDomainException::dataIncomplete('birth_date');
        }

        return $value;
    }

    private function bankAccount(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '' || mb_strlen($normalized) > 160) {
            throw ClientDomainException::dataIncomplete('bank_account');
        }

        return $normalized;
    }

    private function translateCollision(
        QueryException $exception,
        RegisterClientCommand $command,
        DistributorProfile $profile,
        string $requestHash,
    ): ClientRegistrationResult {
        $message = mb_strtolower($exception->getMessage());
        if (str_contains($message, 'client_registration_idempotency')) {
            $replayed = $this->replayed($command, $profile, $requestHash);
            if ($replayed !== null) {
                return $replayed;
            }
        }
        if (str_contains($message, 'curp_hmac')) {
            $this->audit->record(
                'CLIENT_CURP_EXISTS',
                null,
                $command->actor,
                $profile->distributorId,
                null,
                [],
                'REJECTED',
                $command->requestId,
            );
            throw ClientDomainException::curpExists();
        }
        if (str_contains($message, 'address_fingerprint_hmac')) {
            $this->audit->record(
                'CLIENT_ADDRESS_EXISTS',
                null,
                $command->actor,
                $profile->distributorId,
                null,
                [],
                'REJECTED',
                $command->requestId,
            );
            throw ClientDomainException::addressExists();
        }

        throw $exception;
    }
}
