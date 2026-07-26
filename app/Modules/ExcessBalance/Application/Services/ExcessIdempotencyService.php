<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Application\Services;

use App\Modules\ExcessBalance\Domain\Exceptions\ExcessBalanceException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ExcessIdempotencyService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return object{id: string, response_payload: mixed, completed_at: mixed}
     */
    public function reserve(
        int $actorId,
        string $operation,
        string $resourceId,
        string $key,
        array $payload,
    ): object {
        $keyHmac = $this->keyHmac($key);
        $requestHash = hash('sha256', json_encode($this->canonicalize($payload), JSON_THROW_ON_ERROR));
        $record = DB::table('excess_idempotency_keys')
            ->where('actor_id', $actorId)
            ->where('operation', $operation)
            ->where('resource_id', $resourceId)
            ->where('key_hmac', $keyHmac)
            ->lockForUpdate()
            ->first();

        if ($record !== null) {
            if (! hash_equals((string) $record->request_hash, $requestHash)) {
                throw ExcessBalanceException::idempotencyConflict();
            }

            return $record;
        }

        DB::table('excess_idempotency_keys')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'actor_id' => $actorId,
            'operation' => $operation,
            'resource_id' => $resourceId,
            'key_hmac' => $keyHmac,
            'request_hash' => $requestHash,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $record = DB::table('excess_idempotency_keys')
            ->where('actor_id', $actorId)
            ->where('operation', $operation)
            ->where('resource_id', $resourceId)
            ->where('key_hmac', $keyHmac)
            ->lockForUpdate()
            ->firstOrFail();
        if (! hash_equals((string) $record->request_hash, $requestHash)) {
            throw ExcessBalanceException::idempotencyConflict();
        }

        return $record;
    }

    /** @param array<string, mixed> $response */
    public function complete(string $id, int $status, array $response): void
    {
        DB::table('excess_idempotency_keys')->where('id', $id)->update([
            'response_status' => $status,
            'response_payload' => json_encode($response, JSON_THROW_ON_ERROR),
            'completed_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function replay(object $record): ?array
    {
        if ($record->completed_at === null || $record->response_payload === null) {
            return null;
        }

        $payload = is_string($record->response_payload)
            ? json_decode($record->response_payload, true, 512, JSON_THROW_ON_ERROR)
            : (array) $record->response_payload;

        return $payload;
    }

    private function keyHmac(string $key): string
    {
        $secret = config('excess_balance.idempotency_hmac_key');
        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('EXCESS_BALANCE_IDEMPOTENCY_HMAC_KEY is not configured.');
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

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
