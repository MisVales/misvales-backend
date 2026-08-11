<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\HasOptimisticLocking;

class RedemptionPeriod extends Model
{
    use HasFactory, HasUuids, HasOptimisticLocking;

    protected $fillable = [
        'code',
        'name',
        'description',
        'starts_at',
        'ends_at',
        'status',
        'point_value',
        'point_value_configuration_version_id',
        'reason',
        'created_by',
        'published_by',
        'published_at',
        'closed_by',
        'closed_at',
        'lock_version',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'published_at' => 'datetime',
        'closed_at' => 'datetime',
        'point_value' => 'decimal:4',
        'lock_version' => 'integer',
        'status' => \App\Enums\RedemptionPeriodStatus::class,
    ];

    public function versionConfiguracionValorPunto()
    {
        return $this->belongsTo(ConfigurationVersion::class, 'point_value_configuration_version_id');
    }
}
