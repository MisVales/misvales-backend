<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Payment\Domain\Enums\BankMovementStatus;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @property string $id @property int $branch_id @property BankMovementStatus $status */
final class BankMovementModel extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'bank_movements';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return BelongsTo<BankImportModel, $this> */
    public function bankImport(): BelongsTo
    {
        return $this->belongsTo(BankImportModel::class, 'bank_import_id');
    }

    /** @return HasOne<PaymentAllocationModel, $this> */
    public function allocation(): HasOne
    {
        return $this->hasOne(PaymentAllocationModel::class, 'bank_movement_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'paid_at' => 'immutable_datetime',
            'raw_payload' => 'array',
            'normalized_payload' => 'array',
            'validation_errors' => 'array',
            'status' => BankMovementStatus::class,
            'processed_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }
}
