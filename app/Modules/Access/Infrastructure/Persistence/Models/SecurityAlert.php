<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

final class SecurityAlert extends Model
{
    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'immutable_datetime',
        ];
    }
}
