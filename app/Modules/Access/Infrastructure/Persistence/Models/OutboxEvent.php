<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Modules\Access\Domain\Security\OutboxState;
use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class OutboxEvent extends Model
{
    use HasPublicUuid;

    /** @var list<string> */
    protected $fillable = ['type', 'payload', 'idempotency_key', 'available_at'];

    /** @var list<string> */
    protected $hidden = ['payload', 'last_error'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'state' => OutboxState::class,
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
