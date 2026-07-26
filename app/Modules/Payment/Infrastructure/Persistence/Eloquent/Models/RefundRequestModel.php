<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Payment\Domain\Enums\RefundRequestStatus;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Persistencia única reutilizada por M12 para el flujo de devolución.
 *
 * @property string $id
 * @property string $request_number
 * @property string $excess_balance_id
 * @property int $distributor_id
 * @property int $branch_id
 * @property string $amount
 * @property RefundRequestStatus $status
 * @property int $requested_by
 * @property int|null $authorized_by
 * @property int|null $executed_by
 * @property string|null $refund_method
 * @property string|null $refund_reference
 * @property string|null $evidence_media_file_id
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $refund_date
 * @property int $lock_version
 */
final class RefundRequestModel extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'refund_requests';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'status' => RefundRequestStatus::class,
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'refund_date' => 'immutable_date',
            'lock_version' => 'integer',
        ];
    }
}
