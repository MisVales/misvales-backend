<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Voucher\Domain\Aggregates\Voucher;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Adaptador del registro propietario de M08 usado por M09.
 *
 * @property string $id
 * @property string $folio
 * @property string $type
 * @property VoucherStatus $status
 * @property int $branch_id
 * @property string $client_id
 * @property string $distributor_id
 * @property string $product_id
 * @property string $product_version_id
 * @property string $capital_amount
 * @property array<string, mixed> $financial_snapshot
 * @property int $lock_version
 * @property CarbonImmutable $generated_at
 * @property CarbonImmutable|null $opened_at
 * @property CarbonImmutable|null $released_at
 * @property CarbonImmutable|null $rejected_at
 * @property CarbonImmutable|null $fulfilled_at
 */
final class VoucherModel extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'vouchers';

    /** M09 no acepta asignación masiva de datos del cliente. */
    protected $guarded = ['*'];

    public function toAggregate(): Voucher
    {
        return new Voucher($this->id, $this->status, $this->lock_version);
    }

    protected function casts(): array
    {
        return [
            'status' => VoucherStatus::class,
            'capital_amount' => 'decimal:4',
            'financial_snapshot' => 'array',
            'lock_version' => 'integer',
            'generated_at' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
            'fulfilled_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException('Los vales no se eliminan.'));
    }
}
