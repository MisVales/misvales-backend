<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Infrastructure\Persistence\Models;

use App\Modules\Mobility\Domain\Enums\AdministrativeReassignmentStatus;
use App\Modules\Mobility\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property string $id
 * @property string $reassignment_number
 * @property AdministrativeReassignmentStatus $status
 * @property int|null $scope_branch_id
 * @property string $reason
 * @property int $executed_by
 * @property string $executed_role
 * @property int|null $reauthentication_id
 * @property string $idempotency_key
 * @property string $request_hash
 * @property int $lock_version
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property-read Collection<int, AdministrativeReassignmentItem> $items
 */
final class AdministrativeReassignment extends Model
{
    use UsesUuidPrimaryKey;

    protected $guarded = ['*'];

    /** @return HasMany<AdministrativeReassignmentItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(AdministrativeReassignmentItem::class);
    }

    protected function casts(): array
    {
        return [
            'status' => AdministrativeReassignmentStatus::class,
            'validated_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException('Las reasignaciones no se eliminan.'));
    }
}
