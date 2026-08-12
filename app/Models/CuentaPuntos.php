<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class CuentaPuntos extends Model
{
    use HasUuids;

    protected $table = 'point_accounts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['balance' => 'integer', 'reserved' => 'integer', 'lock_version' => 'integer'];
    }
}
