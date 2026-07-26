<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\ExcessBalance\Domain\Enums\ExcessBalanceBucket;
use App\Modules\ExcessBalance\Domain\Enums\ExcessLedgerEntryType;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $excess_balance_id
 * @property ExcessLedgerEntryType $entry_type
 * @property string $amount
 * @property ExcessBalanceBucket|null $balance_bucket_from
 * @property ExcessBalanceBucket|null $balance_bucket_to
 */
final class ExcessLedgerEntryModel extends Model
{
    use UsesUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $table = 'excess_ledger_entries';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'entry_type' => ExcessLedgerEntryType::class,
            'balance_bucket_from' => ExcessBalanceBucket::class,
            'balance_bucket_to' => ExcessBalanceBucket::class,
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
