<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

final class SecurityEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'before_state' => 'array',
            'after_state' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
