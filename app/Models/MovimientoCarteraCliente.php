<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovimientoCarteraCliente extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'client_portfolio_entries';

    protected $fillable = ['client_id', 'distributor_id', 'entry_type', 'amount', 'informational_status', 'occurred_at', 'due_date', 'last_payment_at', 'note', 'related_voucher_id', 'recorded_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4', 'occurred_at' => 'immutable_datetime', 'due_date' => 'immutable_date', 'last_payment_at' => 'immutable_datetime', 'lock_version' => 'integer'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'client_id');
    }

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distributor_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
