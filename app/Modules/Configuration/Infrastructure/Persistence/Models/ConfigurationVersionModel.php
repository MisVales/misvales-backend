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
 * Versión de una configuración: valor, estado, vigencia, responsable y motivo.
 *
 * @property int                     $id
 * @property string                  $public_id
 * @property int                     $definition_id
 * @property int                     $version_number
 * @property string                  $value
 * @property string                  $status
 * @property \Carbon\CarbonImmutable|null $effective_from
 * @property \Carbon\CarbonImmutable|null $effective_to
 * @property int                     $created_by
 * @property int|null                $published_by
 * @property \Carbon\CarbonImmutable|null $published_at
 * @property string|null             $reason
 * @property int                     $lock_version
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property-read ConfigurationDefinitionModel $definition
 * @property-read User               $creator
 * @property-read User|null          $publisher
 */
final class ConfigurationVersionModel extends Model
{
    use HasPublicUuid;

    protected $table = 'configuration_versions';

    protected $guarded = ['id', 'public_id', 'version_number', 'status', 'published_by', 'published_at'];

    /** @return BelongsTo<ConfigurationDefinitionModel, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(ConfigurationDefinitionModel::class, 'definition_id');
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
        self::deleting(fn (): never => throw new LogicException('Las versiones de configuración no se eliminan.'));
    }
}
