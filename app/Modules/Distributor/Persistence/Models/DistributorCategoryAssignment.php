<?php

namespace App\Modules\Distributor\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributorCategoryAssignment extends Model
{
    use HasUuids;

    protected $table = 'distributor_category_assignments';

    protected $fillable = [
        'distributor_id',
        'category_id',
        'category_version_id',
        'profit_rate_snapshot',
        'effective_from',
        'effective_to',
        'assigned_by',
        'assigned_role',
        'assigned_branch_id',
        'reason',
        'idempotency_key',
    ];

    protected $casts = [
        'profit_rate_snapshot' => 'decimal:4',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'distributor_id');
    }
}
