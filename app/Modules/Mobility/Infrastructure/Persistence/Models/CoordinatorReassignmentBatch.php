<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Infrastructure\Persistence\Models;

use App\Modules\Mobility\Domain\Enums\CoordinatorReassignmentStatus;
use App\Modules\Mobility\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $batch_number
 * @property int $outgoing_coordinator_id
 * @property int $branch_id
 * @property CoordinatorReassignmentStatus $status
 * @property string $reason
 * @property int $snapshot_count
 * @property int $current_count
 * @property string $idempotency_key
 * @property string $request_hash
 * @property int $lock_version
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property-read Collection<int, CoordinatorReassignmentItem> $items
 */
final class CoordinatorReassignmentBatch extends Model
{
    use UsesUuidPrimaryKey;

    protected $guarded = ['*'];

    /** @return HasMany<CoordinatorReassignmentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CoordinatorReassignmentItem::class, 'batch_id');
    }

    protected function casts(): array
    {
        return [
            'status' => CoordinatorReassignmentStatus::class,
            'snapshot_count' => 'integer',
            'current_count' => 'integer',
            'completed_at' => 'immutable_datetime',
            'lock_version' => 'integer',
            'active_slot' => 'boolean',
        ];
    }
}
