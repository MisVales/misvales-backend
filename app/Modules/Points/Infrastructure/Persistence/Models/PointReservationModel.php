<?php

declare(strict_types=1);

namespace App\Modules\Points\Infrastructure\Persistence\Models;

use App\Modules\Points\Domain\Enums\PointReservationStatus;
use App\Modules\Points\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property string $point_account_id
 * @property string $redemption_request_id
 * @property int $points
 * @property PointReservationStatus $status
 * @property CarbonImmutable $reserved_at
 * @property CarbonImmutable|null $released_at
 * @property CarbonImmutable|null $consumed_at
 */
final class PointReservationModel extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'point_reservations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => PointReservationStatus::class,
            'points' => 'integer',
            'reserved_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $reservation): void {
            if ($reservation->isDirty(['points', 'point_account_id', 'redemption_request_id'])) {
                throw new LogicException('El origen y monto de una reserva son inmutables.');
            }
            if ($reservation->isDirty('status')) {
                $from = PointReservationStatus::from((string) $reservation->getOriginal('status'));
                $to = $reservation->status;
                if ($from !== PointReservationStatus::ACTIVE
                    || ! in_array($to, [PointReservationStatus::RELEASED, PointReservationStatus::CONSUMED], true)) {
                    throw new LogicException('La transición de reserva no está permitida.');
                }
            }
        });
        self::deleting(fn (): never => throw new LogicException('Las reservas de puntos no se eliminan.'));
    }
}
