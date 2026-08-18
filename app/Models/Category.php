<?php

namespace App\Models;

use App\Enums\BaseStatus;
use App\Models\Concerns\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory, HasOptimisticLocking, HasUuids;

    protected $fillable = [
        'code',
        'status',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => BaseStatus::class,
    ];

    public function versions()
    {
        return $this->hasMany(CategoryVersion::class, 'category_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
