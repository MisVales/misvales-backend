<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Application\Services;

use App\Modules\Voucher\Domain\Exceptions\VoucherDomainException;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\VoucherIdempotencyModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Reserva idempotencia antes de bloquear el agregado. */
final class IdempotencyService
{
    /** @param array<string, mixed> $payload */
    public function reserve(int $actorId, string $operation, string $key, array $payload): VoucherIdempotencyModel
    {
        $keyHmac = $this->keyHmac($key);
        $requestHash = hash('sha256', json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
        $existing = VoucherIdempotencyModel::query()
            ->where('actor_id', $actorId)
            ->where('operation', $operation)
            ->where('key_hmac', $keyHmac)
            ->lockForUpdate()
            ->first();
        if ($existing !== null) {
            if (! hash_equals((string) $existing->request_hash, $requestHash)) {
                throw VoucherDomainException::idempotencyReused();
            }

            return $existing;
        }

        DB::table('voucher_idempotency_keys')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'actor_id' => $actorId,
            'operation' => $operation,
            'key_hmac' => $keyHmac,
            'request_hash' => $requestHash,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $reservation = VoucherIdempotencyModel::query()
            ->where('actor_id', $actorId)
            ->where('operation', $operation)
            ->where('key_hmac', $keyHmac)
            ->lockForUpdate()
            ->firstOrFail();
        if (! hash_equals((string) $reservation->request_hash, $requestHash)) {
            throw VoucherDomainException::idempotencyReused();
        }

        return $reservation;
    }

    /** @param array<string, mixed> $response */
    public function complete(VoucherIdempotencyModel $reservation, int $status, array $response): void
    {
        $reservation->forceFill([
            'response_status' => $status,
            'response_payload' => $response,
            'completed_at' => now('UTC'),
        ])->save();
    }

    public function keyHmac(string $key): string
    {
        $secret = config('voucher.idempotency_hmac_key');
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('VOUCHER_IDEMPOTENCY_HMAC_KEY is not configured.');
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
