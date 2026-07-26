<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/** Reserva durable de idempotencia por actor y operación. */
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
