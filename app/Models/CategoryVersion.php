<?php

namespace App\Models;

use App\Enums\VersionStatus;
use App\Models\Concerns\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CategoryVersion extends Model
{
    use HasFactory, HasOptimisticLocking, HasUuids;

    protected $fillable = [
        'category_id',
        'version',
        'name',
        'description',
        'profit_percentage',
        'status',
        'lock_version',
        'effective_from',
        'effective_to',
        'reason',
        'created_by',
        'published_by',
        'published_at',
    ];

    protected $casts = [
        'profit_percentage' => 'decimal:6',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'published_at' => 'datetime',
        'status' => VersionStatus::class,
    ];

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
