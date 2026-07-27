<?php

declare(strict_types=1);

namespace App\Modules\Relation\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class RelationSnapshot extends Model
{
    use HasUuids;

    protected $table = 'relation_snapshots';

    protected $guarded = [];

    protected $casts = [
        'total_credit_line' => 'decimal:4',
        'used_balance' => 'decimal:4',
        'available_balance' => 'decimal:4',
        'points_balance' => 'decimal:4',
        'configuration_versions' => 'array',
        'precision' => 'integer',
    ];

    public function relation()
    {
        return $this->belongsTo(Relation::class, 'relation_id');
    }
}
