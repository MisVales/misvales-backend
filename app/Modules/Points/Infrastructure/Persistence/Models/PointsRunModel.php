<?php

declare(strict_types=1);

namespace App\Modules\Points\Infrastructure\Persistence\Models;

use App\Modules\Points\Domain\Enums\PointsRunStatus;
use App\Modules\Points\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $public_folio
 * @property PointsRunStatus $status
 * @property CarbonImmutable|null $period_start
 * @property CarbonImmutable|null $period_end
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 */
final class PointsRunModel extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'points_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => PointsRunStatus::class,
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
