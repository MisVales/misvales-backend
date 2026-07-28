<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Client\Persistence\Models\Client;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditLineModel;
use App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models\CreditUsageRestrictionModel;
use App\Modules\Distributor\Persistence\Models\Distributor;
use App\Modules\Voucher\Domain\Aggregates\Voucher;
use App\Modules\Voucher\Domain\Enums\VoucherStatus;
use App\Modules\Voucher\Domain\Enums\VoucherType;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Adaptador del registro propietario de M08 usado por M09.
 *
 * @property string $id
 * @property string $folio
 * @property VoucherType $type
 * @property VoucherStatus $status
 * @property int $branch_id
 * @property string $client_id
 * @property string $distributor_id
 * @property string $product_id
 * @property string $product_version_id
 * @property string $capital_amount
 * @property string $credit_available_snapshot
 * @property array<string, mixed> $financial_snapshot
 * @property int $lock_version
 * @property CarbonImmutable $generated_at
 * @property CarbonImmutable|null $opened_at
 * @property CarbonImmutable|null $released_at
 * @property CarbonImmutable|null $rejected_at
 * @property CarbonImmutable|null $fulfilled_at
 * @property-read VoucherFinancialSnapshotModel|null $financialSnapshot
 * @property-read Collection<int, VoucherInstallmentModel> $installments
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

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class, 'distributor_id');
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** @return BelongsTo<CreditLineModel, $this> */
    public function creditLine(): BelongsTo
    {
        return $this->belongsTo(CreditLineModel::class, 'credit_line_id');
    }

    /** @return BelongsTo<CreditUsageRestrictionModel, $this> */
    public function creditRestriction(): BelongsTo
    {
        return $this->belongsTo(CreditUsageRestrictionModel::class, 'credit_usage_restriction_id');
    }

    /** @return HasOne<VoucherFinancialSnapshotModel, $this> */
    public function financialSnapshot(): HasOne
    {
        return $this->hasOne(VoucherFinancialSnapshotModel::class, 'voucher_id');
    }

    /** @return HasMany<VoucherInstallmentModel, $this> */
    public function installments(): HasMany
    {
        return $this->hasMany(VoucherInstallmentModel::class, 'voucher_id')->orderBy('payment_number');
    }

    protected function casts(): array
    {
        return [
            'type' => VoucherType::class,
            'status' => VoucherStatus::class,
            'capital_amount' => 'decimal:4',
            'credit_available_snapshot' => 'decimal:4',
            'restriction_reference_snapshot' => 'decimal:4',
            'restriction_minimum_snapshot' => 'decimal:4',
            'restriction_maximum_snapshot' => 'decimal:4',
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
