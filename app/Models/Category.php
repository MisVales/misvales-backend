<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\HasOptimisticLocking;

class Category extends Model
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
