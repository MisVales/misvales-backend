<?php

declare(strict_types=1);

namespace App\Modules\Relation\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Modules\Relation\Domain\Enums\CutAttemptStatus;

class CutRunDistributor extends Model
{
    use HasUuids;

    protected $table = 'cut_run_distributors';

    protected $guarded = [];

    protected $casts = [
        'status' => CutAttemptStatus::class,
        'error_context' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'attempt_count' => 'integer',
        'lock_version' => 'integer',
    ];

    public function cutRun()
    {
        return $this->belongsTo(CutRun::class, 'cut_run_id');
    }

    public function relation()
    {
        return $this->belongsTo(Relation::class, 'relation_id');
    }
}
