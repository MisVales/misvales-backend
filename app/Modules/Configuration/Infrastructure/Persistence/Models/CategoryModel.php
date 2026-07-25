<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Identidad estable de una categoría de distribuidora.
 *
 * @property int                     $id
 * @property string                  $public_id
 * @property string                  $status
 * @property int                     $created_by
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property-read User               $creator
 */
final class CategoryModel extends Model
{
    use HasPublicUuid;

    protected $table = 'categories';

    protected $guarded = ['id', 'public_id'];

    /** @return HasMany<CategoryVersionModel, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(CategoryVersionModel::class, 'category_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasOne<CategoryVersionModel, $this> */
    public function currentVersion(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CategoryVersionModel::class, 'category_id')
            ->where('status', \App\Modules\Configuration\Domain\Enums\VersionStatus::PUBLISHED->value)
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function casts(): array
    {
        return [
            'created_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException('Las categorías no se eliminan físicamente.'));
    }
}
