<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EventoCambioOrganizacional extends Model
{
    use HasUuids;

    protected $table = 'organizational_change_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
