<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use App\Modules\Configuration\Domain\Enums\VersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Versión de producto: importe y parámetros financieros.
 *
 * @property int                          $id
 * @property string                       $public_id
 * @property int                          $product_id
 * @property int                          $version_number
 * @property string                       $amount
 * @property string                       $loan_commission_rate
 * @property string                       $interest_rate_per_fortnight
 * @property string                       $insurance_amount
 * @property int                          $fortnight_count
 * @property string                       $status
 * @property \Carbon\CarbonImmutable|null $effective_from
 * @property \Carbon\CarbonImmutable|null $effective_to
 * @property int                          $created_by
 * @property int|null                     $published_by
 * @property \Carbon\CarbonImmutable|null $published_at
 * @property string|null                  $reason
 * @property int                          $lock_version
 * @property \Carbon\CarbonImmutable      $created_at
 * @property \Carbon\CarbonImmutable      $updated_at
 * @property-read ProductModel            $product
 * @property-read User                    $creator
 * @property-read User|null               $publisher
 */
final class ProductVersionModel extends Model
{
    use HasPublicUuid;

    protected $table = 'product_versions';

    protected $guarded = ['id', 'public_id', 'version_number', 'status', 'published_by', 'published_at'];

    /** @return BelongsTo<ProductModel, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductModel::class, 'product_id');
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
            'amount' => 'decimal:4',
            'loan_commission_rate' => 'decimal:4',
            'interest_rate_per_fortnight' => 'decimal:4',
            'insurance_amount' => 'decimal:4',
            'fortnight_count' => 'integer',
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
        self::deleting(fn (): never => throw new LogicException('Las versiones de producto no se eliminan.'));
    }
}
