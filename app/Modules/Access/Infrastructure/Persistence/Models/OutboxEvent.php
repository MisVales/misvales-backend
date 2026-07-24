<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed> $payload
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $processed_at
 */
#[Hidden(['payload', 'last_error'])]
final class OutboxEvent extends Model
{
    use HasPublicUuid;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
