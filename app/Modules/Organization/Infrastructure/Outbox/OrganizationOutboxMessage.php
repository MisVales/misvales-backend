<?php

namespace App\Modules\Organization\Infrastructure\Outbox;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class OrganizationOutboxMessage extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'event_id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'occurred_at',
        'available_at',
        'published_at',
        'attempts',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'immutable_datetime',
        'available_at' => 'immutable_datetime',
        'published_at' => 'immutable_datetime',
        'attempts' => 'integer',
    ];
}
