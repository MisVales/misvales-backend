<?php

declare(strict_types=1);

namespace App\Modules\Payment\Application\Services;

use App\Modules\Payment\Application\DTOs\PaymentIdempotencyReservation;
use App\Modules\Payment\Domain\Exceptions\PaymentDomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Reserva idempotencia por actor y operación sin conservar la clave en claro. */
final class PaymentIdempotencyService
{
    /** @param array<string, mixed> $payload */
    public function reserve(int $actorId, string $operation, string $key, array $payload): PaymentIdempotencyReservation
    {
        $keyHmac = $this->keyHmac($key);
        $requestHash = hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR));
        $existing = DB::table('payment_idempotency_keys')
            ->where('actor_id', $actorId)
            ->where('operation', $operation)
            ->where('key_hmac', $keyHmac)
            ->lockForUpdate()
            ->first();
        if ($existing !== null) {
            if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                throw PaymentDomainException::idempotencyReused();
            }
            $replay = $existing->response_payload === null
                ? null
                : json_decode((string) $existing->response_payload, true, 512, JSON_THROW_ON_ERROR);

            return new PaymentIdempotencyReservation($existing, is_array($replay) ? $replay : null);
        }

        $id = (string) Str::uuid();
        DB::table('payment_idempotency_keys')->insertOrIgnore([
            'id' => $id,
            'actor_id' => $actorId,
            'operation' => $operation,
            'key_hmac' => $keyHmac,
            'request_hash' => $requestHash,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $record = DB::table('payment_idempotency_keys')
            ->where('actor_id', $actorId)
            ->where('operation', $operation)
            ->where('key_hmac', $keyHmac)
            ->lockForUpdate()
            ->firstOrFail();
        if (! hash_equals((string) $record->request_hash, $requestHash)) {
            throw PaymentDomainException::idempotencyReused();
        }

        return new PaymentIdempotencyReservation($record, null);
    }

    /** @param array<string, mixed> $response */
    public function complete(string $recordId, int $status, array $response): void
    {
        DB::table('payment_idempotency_keys')->where('id', $recordId)->update([
            'response_status' => $status,
            'response_payload' => json_encode($response, JSON_THROW_ON_ERROR),
            'completed_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }

    private function keyHmac(string $key): string
    {
        $secret = config('payment.idempotency_hmac_key');
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('PAYMENT_IDEMPOTENCY_HMAC_KEY is not configured.');
        }

        return hash_hmac('sha256', $key, $secret);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
