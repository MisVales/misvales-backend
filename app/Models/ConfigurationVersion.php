<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConfigurationVersion extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'configuration_definition_id',
        'version',
        'value',
        'status',
        'effective_from',
        'effective_to',
        'reason',
        'created_by',
        'published_by',
        'published_at',
    ];

    protected $casts = [
        'value' => 'array',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'published_at' => 'datetime',
        'status' => \App\Enums\VersionStatus::class,
    ];

    public function definition()
    {
        return $this->belongsTo(ConfigurationDefinition::class, 'configuration_definition_id');
    }
}
