<?php

declare(strict_types=1);

namespace App\Modules\Points\Infrastructure\Persistence\Models;

use App\Modules\Points\Domain\Exceptions\PointsDomainException;
use App\Modules\Points\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Cuenta materializada; solo los casos de uso de M13 pueden modificarla.
 *
 * @property string $id
 * @property int $distributor_id
 * @property int $total_points
 * @property int $reserved_points
 * @property int $available_points
 * @property int $lock_version
 * @property CarbonImmutable|null $last_movement_at
 */
final class PointAccountModel extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'point_accounts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total_points' => 'integer',
            'reserved_points' => 'integer',
            'available_points' => 'integer',
            'lock_version' => 'integer',
            'last_movement_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $account): void {
            $total = (int) $account->total_points;
            $reserved = (int) $account->reserved_points;
            if ($total < 0 || $reserved < 0 || $reserved > $total) {
                throw new PointsDomainException(
                    'POINT_ACCOUNT_INCONSISTENT',
                    'La operación produciría un saldo de puntos inválido.',
                    409,
                );
            }

            $account->available_points = $total - $reserved;
        });
        self::deleting(fn (): never => throw new LogicException('Las cuentas de puntos no se eliminan.'));
    }
}
