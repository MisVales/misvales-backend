<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordHistory extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['user_id', 'password_hash', 'recorded_at'];

    /** @var list<string> */
    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime'];
    }
}
