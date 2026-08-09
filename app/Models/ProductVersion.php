<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Models\Concerns\HasOptimisticLocking;

class ProductVersion extends Model
{
    use HasFactory, HasUuids, HasOptimisticLocking;

    protected $fillable = [
        'product_id',
        'version',
        'name',
        'description',
        'nominal_amount',
        'loan_commission_percentage',
        'simple_interest_percentage',
        'insurance_amount',
        'fortnights_count',
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
        'nominal_amount' => 'decimal:4',
        'loan_commission_percentage' => 'decimal:6',
        'simple_interest_percentage' => 'decimal:6',
        'insurance_amount' => 'decimal:4',
        'fortnights_count' => 'integer',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'published_at' => 'datetime',
        'status' => \App\Enums\VersionStatus::class,
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
