<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\HasOptimisticLocking;

class Product extends Model
{
    use HasFactory, HasUuids, HasOptimisticLocking;

    protected $fillable = [
        'code',
        'status',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => \App\Enums\BaseStatus::class,
    ];

    public function versions()
    {
        return $this->hasMany(ProductVersion::class, 'product_id');
    }
}
