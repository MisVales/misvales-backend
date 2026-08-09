<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsignacionCategoriaDistribuidora extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'distributor_category_assignments';

    protected $fillable = [
        'distributor_id',
        'category_version_id',
        'starts_at',
        'ends_at',
        'assigned_by',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
        ];
    }

    public function distribuidora(): BelongsTo
    {
        return $this->belongsTo(Distribuidora::class, 'distributor_id');
    }

    public function versionCategoria(): BelongsTo
    {
        return $this->belongsTo(CategoryVersion::class, 'category_version_id');
    }

    public function asignadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
