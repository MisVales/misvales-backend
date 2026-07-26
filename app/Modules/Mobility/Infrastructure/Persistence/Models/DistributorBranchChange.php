<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Infrastructure\Persistence\Models;

use App\Modules\Mobility\Domain\Enums\BranchChangeStatus;
use App\Modules\Mobility\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $change_number
 * @property string $distributor_id
 * @property int $origin_branch_id
 * @property int $destination_branch_id
 * @property int|null $destination_coordinator_id
 * @property BranchChangeStatus $status
 * @property string $reason
 * @property int $requested_by
 * @property string $idempotency_key
 * @property string $request_hash
 * @property int $lock_version
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property-read Collection<int, BranchChangeClientItem> $clientItems
 */
final class DistributorBranchChange extends Model
{
    use UsesUuidPrimaryKey;

    protected $guarded = ['*'];

    /** @return HasMany<BranchChangeClientItem, $this> */
    public function clientItems(): HasMany
    {
        return $this->hasMany(BranchChangeClientItem::class, 'branch_change_id');
    }

    protected function casts(): array
    {
        return [
            'status' => BranchChangeStatus::class,
            'authorized_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'lock_version' => 'integer',
            'active_slot' => 'boolean',
        ];
    }
}
