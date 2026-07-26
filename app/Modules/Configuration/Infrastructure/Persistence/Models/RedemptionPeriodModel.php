<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Periodo de canje de puntos.
 *
 * @property int $id
 * @property string $public_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $status
 * @property string|null $reason
 * @property int $created_by
 * @property int|null $published_by
 * @property CarbonImmutable|null $published_at
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User                    $creator
 * @property-read User|null               $publisher
 */
final class RedemptionPeriodModel extends Model
{
    use HasPublicUuid;

    protected $table = 'redemption_periods';

    protected $guarded = ['id', 'public_id', 'status', 'published_by', 'published_at'];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function versionStatus(): VersionStatus
    {
        return $this->status === 'CLOSED'
            ? VersionStatus::INACTIVE
            : VersionStatus::from($this->status);
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'lock_version' => 'integer',
            'created_by' => 'integer',
            'published_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException('Los periodos de canje no se eliminan.'));
    }
}
