<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoordinatorDistributorAssignment extends Model
{
    protected $fillable = [
        'distributor_id',
        'coordinator_user_id',
        'branch_id',
        'starts_at',
        'ends_at',
        'assigned_by',
        'source_type',
        'source_id',
        'reason'
    ];

    protected $hidden = [
        'id',
        'distributor_id',
        'coordinator_user_id',
        'branch_id',
        'assigned_by'
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::uuid();
            }
        });
    }


    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function distributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'distributor_id');
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_user_id');
    }

}