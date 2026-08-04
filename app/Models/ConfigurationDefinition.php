<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Concerns\HasOptimisticLocking;

class ConfigurationDefinition extends Model
{
    use HasFactory, HasUuids, HasOptimisticLocking;

    protected $fillable = [
        'key',
        'name',
        'description',
        'value_type',
        'unit',
        'is_required',
        'is_sensitive',
        'status',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_sensitive' => 'boolean',
        'status' => \App\Enums\BaseStatus::class,
    ];

    public function versions()
    {
        return $this->hasMany(ConfigurationVersion::class, 'configuration_definition_id');
    }
}
