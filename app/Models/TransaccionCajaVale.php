<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class TransaccionCajaVale extends Model
{
    use HasUuids;

    protected $table = 'voucher_cash_transactions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cashed_at' => 'immutable_datetime'];
    }
}
