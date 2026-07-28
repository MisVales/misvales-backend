<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Reserva durable de idempotencia por actor y operación.
 *
 * @property array<string, mixed>|null $response_payload
 * @property CarbonImmutable|null $completed_at
 */
final class VoucherIdempotencyModel extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'voucher_idempotency_keys';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'response_payload' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
