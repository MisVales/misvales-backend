<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Reserva y resultado de una operación idempotente.
 *
 * @property string $scope_key
 * @property string $request_hash
 * @property array<string, mixed>|null $response_payload
 * @property CarbonImmutable|null $completed_at
 */
final class OnboardingIdempotencyKey extends Model
{
    /** @var list<string> */
    protected $guarded = ['id', 'application_id', 'operation', 'scope_key', 'idempotency_key', 'request_hash'];

    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
