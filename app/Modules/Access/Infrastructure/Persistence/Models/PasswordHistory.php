<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps previous password hashes so password reuse can be rejected.
 */
#[Hidden(['password_hash'])]
final class PasswordHistory extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
