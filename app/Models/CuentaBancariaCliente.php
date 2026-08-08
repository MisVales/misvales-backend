<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CuentaBancariaCliente extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'client_bank_accounts';

    protected $fillable = ['client_id', 'bank_name', 'account_holder_name', 'is_current', 'starts_at', 'ends_at', 'created_by', 'change_reason'];

    protected $hidden = ['account_number_ciphertext', 'account_number_hmac', 'clabe_ciphertext', 'clabe_hmac'];

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'client_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
