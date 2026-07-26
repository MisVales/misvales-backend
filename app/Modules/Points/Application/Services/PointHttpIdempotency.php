<?php

declare(strict_types=1);

namespace App\Modules\Points\Application\Services;

use App\Models\User;
use App\Modules\Points\Domain\Exceptions\PointsDomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PointHttpIdempotency
{
    /**
     * @param  array<string, mixed>  $body
     * @return array{replayed: bool, resource_id: string|null}
     */
    public function claim(User $actor, string $route, string $key, array $body): array
    {
        if ($key === '') {
            throw new PointsDomainException(
                'IDEMPOTENCY_KEY_REQUIRED',
                'La operación requiere el encabezado Idempotency-Key.',
                422,
            );
        }

        $hash = hash('sha256', json_encode($this->canonicalize($body), JSON_THROW_ON_ERROR));
        $existing = DB::table('point_idempotency_records')
            ->where('actor_id', $actor->id)
            ->where('route', $route)
            ->where('idempotency_key', $key)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if (! hash_equals((string) $existing->request_hash, $hash)) {
                throw new PointsDomainException(
                    'IDEMPOTENCY_KEY_REUSED',
                    'La clave de idempotencia ya fue usada con otra petición.',
                    409,
                );
            }
            if ($existing->response_status === null) {
                throw new PointsDomainException(
                    'CONCURRENT_POINT_OPERATION',
                    'La misma operación todavía está en proceso.',
                    409,
                );
            }

            $response = json_decode((string) $existing->response_body, true);

            return [
                'replayed' => true,
                'resource_id' => is_array($response) && is_string($response['resource_id'] ?? null)
                    ? $response['resource_id']
                    : null,
            ];
        }

        $inserted = DB::table('point_idempotency_records')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'actor_id' => $actor->id,
            'route' => $route,
            'idempotency_key' => $key,
            'request_hash' => $hash,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        if ($inserted === 0) {
            $concurrent = DB::table('point_idempotency_records')
                ->where('actor_id', $actor->id)
                ->where('route', $route)
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();
            if ($concurrent === null || ! hash_equals((string) $concurrent->request_hash, $hash)) {
                throw new PointsDomainException(
                    'IDEMPOTENCY_KEY_REUSED',
                    'La clave de idempotencia ya fue usada con otra petición.',
                    409,
                );
            }
            if ($concurrent->response_status === null) {
                throw new PointsDomainException(
                    'CONCURRENT_POINT_OPERATION',
                    'La misma operación todavía está en proceso.',
                    409,
                );
            }
            $response = json_decode((string) $concurrent->response_body, true);

            return [
                'replayed' => true,
                'resource_id' => is_array($response) && is_string($response['resource_id'] ?? null)
                    ? $response['resource_id']
                    : null,
            ];
        }

        return ['replayed' => false, 'resource_id' => null];
    }

    public function complete(User $actor, string $route, string $key, string $resourceId, int $status = 200): void
    {
        DB::table('point_idempotency_records')
            ->where('actor_id', $actor->id)
            ->where('route', $route)
            ->where('idempotency_key', $key)
            ->update([
                'response_status' => $status,
                'response_body' => json_encode(['resource_id' => $resourceId], JSON_THROW_ON_ERROR),
                'updated_at' => now('UTC'),
            ]);
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
