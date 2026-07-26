<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Infrastructure\Persistence\Models;

use App\Modules\Mobility\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $batch_id
 * @property string $distributor_id
 * @property int $origin_coordinator_id
 * @property int|null $destination_coordinator_id
 * @property string $status
 */
final class CoordinatorReassignmentItem extends Model
{
    use UsesUuidPrimaryKey;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['completed_at' => 'immutable_datetime'];
    }
}
