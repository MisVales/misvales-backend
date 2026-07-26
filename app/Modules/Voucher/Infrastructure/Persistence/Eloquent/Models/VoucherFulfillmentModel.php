<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property string $voucher_id
 * @property int $branch_id
 * @property string $client_bank_account_id
 * @property string $capital_amount
 * @property string|null $transaction_number_encrypted
 * @property string|null $transaction_number_hmac
 * @property int $released_by
 * @property CarbonImmutable $released_at
 * @property int|null $fulfilled_by
 * @property CarbonImmutable|null $fulfilled_at
 * @property int $lock_version
 */
#[Hidden(['transaction_number_encrypted', 'transaction_number_hmac'])]
final class VoucherFulfillmentModel extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'voucher_fulfillments';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'capital_amount' => 'decimal:4',
            'released_at' => 'immutable_datetime',
            'fulfilled_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException('Los feriados no se eliminan.'));
    }
}
