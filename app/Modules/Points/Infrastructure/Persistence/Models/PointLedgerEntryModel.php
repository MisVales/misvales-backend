<?php

declare(strict_types=1);

namespace App\Modules\Points\Infrastructure\Persistence\Models;

use App\Modules\Points\Domain\Enums\PointLedgerDirection;
use App\Modules\Points\Domain\Enums\PointLedgerType;
use App\Modules\Points\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Entrada inmutable del libro de M13.
 *
 * @property string $id
 * @property string $point_account_id
 * @property int $distributor_id
 * @property PointLedgerType $type
 * @property PointLedgerDirection $direction
 * @property int $points
 * @property int $signed_points
 * @property int $balance_before
 * @property int $balance_after
 * @property int $reserved_before
 * @property int $reserved_after
 * @property string|null $relation_id
 * @property string|null $redemption_request_id
 * @property string|null $point_evaluation_id
 * @property string $rule_code
 * @property string|null $configuration_version_id
 * @property string $reason
 * @property string $source_event_id
 * @property int $branch_id_snapshot
 * @property string $actor_type
 * @property int|null $actor_id
 * @property CarbonImmutable $occurred_at
 */
final class PointLedgerEntryModel extends Model
{
    use UsesUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $table = 'points_ledger_entries';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => PointLedgerType::class,
            'direction' => PointLedgerDirection::class,
            'points' => 'integer',
            'signed_points' => 'integer',
            'balance_before' => 'integer',
            'balance_after' => 'integer',
            'reserved_before' => 'integer',
            'reserved_after' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('El libro de puntos es inmutable.'));
        self::deleting(fn (): never => throw new LogicException('El libro de puntos es inmutable.'));
    }
}
