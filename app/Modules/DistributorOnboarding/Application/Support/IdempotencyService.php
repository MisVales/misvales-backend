<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Application\Support;

use App\Modules\DistributorOnboarding\Domain\Exceptions\OnboardingDomainException;
use App\Modules\DistributorOnboarding\Persistence\Models\OnboardingIdempotencyKey;

/** Reserva claves por operación y detecta reintentos con contenido incompatible. */
final class IdempotencyService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function replay(string $operation, string $key, array $payload): ?array
    {
        $scopeKey = $this->scopeKey($payload);
        $record = OnboardingIdempotencyKey::query()
            ->where('operation', $operation)
            ->where('scope_key', $scopeKey)
            ->where('idempotency_key', $key)
            ->first();

        if ($record === null) {
            return null;
        }
        if (! hash_equals($record->request_hash, hash('sha256', $this->canonicalJson($payload)))) {
            throw OnboardingDomainException::idempotencyKeyReused();
        }

        return $record->completed_at === null ? null : $record->response_payload;
    }

    /** @param array<string, mixed> $payload */
    public function reserve(
        string $operation,
        string $key,
        array $payload,
        ?int $applicationId = null,
    ): IdempotencyReservation {
        $hash = hash('sha256', $this->canonicalJson($payload));
        $scopeKey = $this->scopeKey($payload);
        $record = OnboardingIdempotencyKey::query()
            ->where('operation', $operation)
            ->where('scope_key', $scopeKey)
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();

        if ($record !== null) {
            if (! hash_equals($record->request_hash, $hash)) {
                throw OnboardingDomainException::idempotencyKeyReused();
            }

            return new IdempotencyReservation($record, $record->completed_at === null ? null : $record->response_payload);
        }

        $now = now();
        OnboardingIdempotencyKey::query()->insertOrIgnore([
            'application_id' => $applicationId,
            'operation' => $operation,
            'scope_key' => $scopeKey,
            'idempotency_key' => $key,
            'request_hash' => $hash,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $record = OnboardingIdempotencyKey::query()
            ->where('operation', $operation)
            ->where('scope_key', $scopeKey)
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->firstOrFail();
        if (! hash_equals($record->request_hash, $hash)) {
            throw OnboardingDomainException::idempotencyKeyReused();
        }

        return new IdempotencyReservation($record, null);
    }

    /** @param array<string, mixed> $response */
    public function complete(
        OnboardingIdempotencyKey $record,
        string $resourceType,
        string $resourcePublicId,
        array $response,
    ): void {
        $record->forceFill([
            'resource_type' => $resourceType,
            'resource_public_id' => $resourcePublicId,
            'response_payload' => $response,
            'completed_at' => now(),
        ])->save();
    }

    /** @param array<string, mixed> $payload */
    private function canonicalJson(array $payload): string
    {
        $this->sortRecursively($payload);

        return (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $payload */
    private function scopeKey(array $payload): string
    {
        $scope = array_key_exists('application_id', $payload)
            ? 'application:'.(string) $payload['application_id']
            : 'actor:'.(string) ($payload['actor_id'] ?? '');

        return hash('sha256', $scope);
    }

    /** @param array<string, mixed> $value */
    private function sortRecursively(array &$value): void
    {
        ksort($value);

        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->sortRecursively($item);
            }
        }
    }
}
