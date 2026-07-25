<?php

declare(strict_types=1);

namespace App\Modules\Configuration\Infrastructure\Persistence\Models;

use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use App\Modules\Configuration\Domain\Enums\ConfigurationKey;
use App\Modules\Configuration\Domain\Enums\ConfigurationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Identidad estable de una configuración aprobada.
 *
 * @property int                $id
 * @property string             $public_id
 * @property string             $key
 * @property string             $type
 * @property bool               $is_administrable
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class ConfigurationDefinitionModel extends Model
{
    use HasPublicUuid;

    protected $table = 'configuration_definitions';

    protected $guarded = ['id', 'public_id'];

    /** @return HasMany<ConfigurationVersionModel, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ConfigurationVersionModel::class, 'definition_id');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasOne<ConfigurationVersionModel, $this> */
    public function currentVersion(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ConfigurationVersionModel::class, 'definition_id')
            ->where('status', 'PUBLISHED')
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from');
    }

    public function configurationKey(): ConfigurationKey
    {
        return ConfigurationKey::from($this->key);
    }

    public function configurationType(): ConfigurationType
    {
        return ConfigurationType::from($this->type);
    }

    protected function casts(): array
    {
        return [
            'is_administrable' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException('Las definiciones de configuración no se eliminan.'));
    }
}
