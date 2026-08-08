<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsignacionClienteDistribuidora extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'client_distributor_assignments';

    protected $fillable = ['client_id', 'distributor_id', 'branch_id', 'starts_at', 'ends_at', 'assigned_by', 'reason'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class, 'client_id');
    }

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distributor_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function asignadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
