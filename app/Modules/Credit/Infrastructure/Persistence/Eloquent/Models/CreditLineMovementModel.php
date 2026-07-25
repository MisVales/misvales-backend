<?php

declare(strict_types=1);

namespace App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use App\Modules\Credit\Domain\Enums\CreditMovementType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property string $public_id
 * @property int $credit_line_id
 * @property CreditMovementType $type
 * @property string $total_delta
 * @property string $used_delta
 * @property string $total_before
 * @property string $total_after
 * @property string $used_before
 * @property string $used_after
 * @property string $available_before
 * @property string $available_after
 * @property string $source_type
 * @property string $source_id
 * @property string $reason
 * @property array<string, mixed>|null $configuration_snapshot
 * @property CarbonImmutable $occurred_at
 */
final class CreditLineMovementModel extends Model
{
    use HasPublicUuid;

    protected $table = 'credit_line_movements';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => CreditMovementType::class,
            'total_delta' => 'decimal:4',
            'used_delta' => 'decimal:4',
            'total_before' => 'decimal:4',
            'total_after' => 'decimal:4',
            'used_before' => 'decimal:4',
            'used_after' => 'decimal:4',
            'available_before' => 'decimal:4',
            'available_after' => 'decimal:4',
            'configuration_snapshot' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Los movimientos de crédito son inmutables.'));
        self::deleting(fn (): never => throw new LogicException('Los movimientos de crédito son inmutables.'));
    }
}
