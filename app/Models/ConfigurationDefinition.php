<?php

namespace App\Models;

use App\Enums\BaseStatus;
use App\Models\Concerns\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConfigurationDefinition extends Model
{
    use HasFactory, HasOptimisticLocking, HasUuids;

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
        'status' => BaseStatus::class,
    ];

    public function versions()
    {
        return $this->hasMany(ConfigurationVersion::class, 'configuration_definition_id');
    }
}
