<?php

declare(strict_types=1);

namespace App\Modules\RiskDelinquency\Infrastructure\Persistence\Models;

use App\Modules\RiskDelinquency\Domain\Enums\RiskSequenceStatus;
use App\Modules\RiskDelinquency\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property string $id
 * @property int $distributor_id
 * @property RiskSequenceStatus $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $last_incorporated_at
 * @property int $breach_count
 * @property string|null $reset_reason
 * @property string|null $breaking_relation_id
 * @property CarbonImmutable|null $regularized_at
 * @property int $version
 * @property-read Collection<int, RiskSequenceRelation> $relations
 */
final class RiskSequence extends Model
{
    use UsesUuidPrimaryKey;

    protected $guarded = [];

    /** @return HasMany<RiskSequenceRelation, $this> */
    public function relations(): HasMany
    {
        return $this->hasMany(RiskSequenceRelation::class, 'risk_sequence_id')->orderBy('position');
    }

    protected function casts(): array
    {
        return [
            'status' => RiskSequenceStatus::class,
            'breach_count' => 'integer',
            'version' => 'integer',
            'started_at' => 'immutable_datetime',
            'last_incorporated_at' => 'immutable_datetime',
            'regularized_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException('Las secuencias de riesgo no se eliminan.'));
    }
}
