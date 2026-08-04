<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MfaRecoveryCode extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false; // Manejamos custom timestamps

    protected $fillable = [
        'user_id',
        'batch_id',
        'code_hash',
        'position',
        'generated_at',
        'used_at',
        'revoked_at',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
