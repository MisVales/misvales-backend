<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Application\Services;

use App\Models\User;
use App\Modules\RiskDelinquency\Application\Contracts\RiskClock;
use App\Modules\RiskDelinquency\Domain\Exceptions\RiskDelinquencyException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RiskHttpIdempotency
{
    public function __construct(private readonly RiskClock $clock) {}

    /** @param array<string, mixed> $payload */
    public function replayedResource(User $actor, string $operation, string $key, array $payload): ?string
    {
        if ($key === '' || mb_strlen($key) > 160) {
            throw new RiskDelinquencyException('IDEMPOTENCY_KEY_REQUIRED', 'La operación requiere Idempotency-Key.', 422);
        }
        $hash = hash('sha256', json_encode($this->canonical($payload), JSON_THROW_ON_ERROR));
        $existing = DB::table('risk_idempotency_records')
            ->where('actor_id', $actor->id)
            ->where('operation', $operation)
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();
        if ($existing !== null) {
            if (! hash_equals((string) $existing->request_hash, $hash)) {
                throw new RiskDelinquencyException('IDEMPOTENCY_KEY_REUSED', 'La clave ya fue usada con otra petición.', 409);
            }
            if ($existing->response_status === null) {
                throw new RiskDelinquencyException('CONCURRENT_RISK_UPDATE', 'La operación idéntica sigue en proceso.', 409);
            }
            $body = json_decode((string) $existing->response_body, true);

            return is_array($body) && is_string($body['resource_id'] ?? null) ? $body['resource_id'] : null;
        }

        $inserted = DB::table('risk_idempotency_records')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'actor_id' => $actor->id,
            'operation' => $operation,
            'idempotency_key' => $key,
            'request_hash' => $hash,
            'created_at' => $this->clock->nowUtc(),
            'updated_at' => $this->clock->nowUtc(),
        ]);
        if ($inserted === 0) {
            throw new RiskDelinquencyException('CONCURRENT_RISK_UPDATE', 'La operación idéntica sigue en proceso.', 409);
        }

        return null;
    }

    public function complete(User $actor, string $operation, string $key, string $resourceId, int $status): void
    {
        DB::table('risk_idempotency_records')
            ->where('actor_id', $actor->id)
            ->where('operation', $operation)
            ->where('idempotency_key', $key)
            ->update([
                'response_status' => $status,
                'response_body' => json_encode(['resource_id' => $resourceId], JSON_THROW_ON_ERROR),
                'updated_at' => $this->clock->nowUtc(),
            ]);
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->canonical(...), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }

        return $value;
    }
}
