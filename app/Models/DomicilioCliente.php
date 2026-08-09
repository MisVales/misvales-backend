<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomicilioCliente extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'client_addresses';

    protected $fillable = ['client_id', 'is_current', 'street', 'exterior_number', 'interior_number', 'neighborhood', 'postal_code', 'municipality', 'city', 'state', 'country', 'address_proof_media_id', 'starts_at', 'ends_at', 'created_by', 'change_reason'];

    protected $hidden = ['normalized_fingerprint_hmac'];

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
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
