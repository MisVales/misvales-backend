<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

class AuthAttempt extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['identifier_hash', 'factor', 'ip_address', 'device_id', 'application', 'window_started_at', 'result', 'occurred_at'];

    /** @var list<string> */
    protected $hidden = ['identifier_hash', 'ip_address', 'device_id'];

    protected function casts(): array
    {
        return ['window_started_at' => 'immutable_datetime', 'occurred_at' => 'immutable_datetime'];
    }
}
