<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CuentaBancariaDistribuidora extends Model
{
    use HasUuids;

    protected $table = 'distributor_bank_accounts';

    protected $fillable = [
        'distributor_id',
        'bank_name',
        'account_holder_name',
        'is_current',
        'starts_at',
        'ends_at',
        'created_by',
        'change_reason',
    ];

    protected $hidden = ['clabe_ciphertext', 'clabe_hmac'];

    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distributor_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
