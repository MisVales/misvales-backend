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
 * Versión de categoría: nombre, descripción, porcentaje de ganancia, estado y vigencia.
 *
 * @property int $id
 * @property string $public_id
 * @property int $category_id
 * @property int $version_number
 * @property string $name
 * @property string $description
 * @property string $distributor_profit_rate
 * @property string $status
 * @property CarbonImmutable|null $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property int $created_by
 * @property int|null $published_by
 * @property CarbonImmutable|null $published_at
 * @property string|null $reason
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read CategoryModel           $category
 * @property-read User                    $creator
 * @property-read User|null               $publisher
 */
final class CategoryVersionModel extends Model
{
    use HasPublicUuid;

    protected $table = 'category_versions';

    protected $guarded = ['id', 'public_id', 'version_number', 'status', 'published_by', 'published_at'];

    /** @return BelongsTo<CategoryModel, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CategoryModel::class, 'category_id');
    }

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
        return VersionStatus::from($this->status);
    }

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'distributor_profit_rate' => 'decimal:4',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'lock_version' => 'integer',
            'created_by' => 'integer',
            'published_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException('Las versiones de categoría no se eliminan.'));
    }
}
