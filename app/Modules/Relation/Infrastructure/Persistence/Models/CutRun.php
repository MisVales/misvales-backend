<?php

declare(strict_types=1);

namespace App\Modules\Relation\Infrastructure\Persistence\Models;

use App\Modules\Relation\Domain\Enums\CutRunStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CutRun extends Model
{
    use HasUuids;

    protected $table = 'cut_runs';

    protected $guarded = [];

    protected $casts = [
        'cut_date' => 'date',
        'status' => CutRunStatus::class,
        'configuration_snapshot' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'distributors_evaluated' => 'integer',
        'relations_generated' => 'integer',
        'distributors_without_items' => 'integer',
        'failed_attempts' => 'integer',
        'lock_version' => 'integer',
    ];

    public function distributors()
    {
        return $this->hasMany(CutRunDistributor::class, 'cut_run_id');
    }

    public function generatedRelations()
    {
        return $this->hasMany(Relation::class, 'cut_run_id');
    }
}
