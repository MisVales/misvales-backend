<?php

namespace App\Models;

use App\Enums\BaseStatus;
use App\Models\Concerns\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory, HasOptimisticLocking, HasUuids;

    protected $fillable = [
        'code',
        'status',
        'loan_commission_percentage',
        'simple_interest_percentage',
        'insurance_amount',
        'fortnights_count',
        'late_fee_amount',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => BaseStatus::class,
        'loan_commission_percentage' => 'decimal:6',
        'simple_interest_percentage' => 'decimal:6',
        'insurance_amount' => 'decimal:4',
        'fortnights_count' => 'integer',
        'late_fee_amount' => 'decimal:4',
    ];

    public function versions()
    {
        return $this->hasMany(ProductVersion::class, 'product_id');
    }
}
