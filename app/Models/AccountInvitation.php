<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountInvitation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'created_by_user_id',
        'purpose',
        'token_hash',
        'exchange_token_hash',
        'state',
        'expires_at',
        'inspected_at',
        'prepared_at',
        'consumed_at',
        'revoked_at',
        'exchange_expires_at',
        'mfa_setup_completed_at',
        'recovery_codes_confirmed_at',
        'attempt_count',
        'last_attempt_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'inspected_at' => 'datetime',
        'prepared_at' => 'datetime',
        'consumed_at' => 'datetime',
        'revoked_at' => 'datetime',
        'exchange_expires_at' => 'datetime',
        'mfa_setup_completed_at' => 'datetime',
        'recovery_codes_confirmed_at' => 'datetime',
        'last_attempt_at' => 'datetime',
    ];

    /**
     * Get the user that this invitation is for.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the user that created this invitation.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Check if the invitation is active and not expired.
     */
    public function isValid(): bool
    {
        return in_array($this->state, ['ACTIVE', 'PREPARED']) 
            && $this->expires_at->isFuture();
    }
}
