<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Infrastructure\Persistence\Models;

use App\Modules\Mobility\Domain\Enums\ClientTransferStatus;
use App\Modules\Mobility\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Agregado persistente de transferencia; sus estados solo cambian en casos de uso M15.
 *
 * @property string $id
 * @property string $transfer_number
 * @property string $client_id
 * @property string $origin_distributor_id
 * @property string $recipient_distributor_id
 * @property int $origin_branch_id
 * @property int|null $origin_coordinator_id
 * @property ClientTransferStatus $status
 * @property string $total_due_snapshot
 * @property string $overdue_snapshot
 * @property int $requested_by
 * @property int|null $preaccepted_by
 * @property int|null $origin_decided_by
 * @property int|null $final_accepted_by
 * @property string|null $reason
 * @property string $idempotency_key
 * @property string $request_hash
 * @property int $client_version
 * @property int $portfolio_version
 * @property int $lock_version
 * @property bool|null $active_slot
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 */
final class ClientTransfer extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'client_transfer_requests';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => ClientTransferStatus::class,
            'total_due_snapshot' => 'decimal:4',
            'overdue_snapshot' => 'decimal:4',
            'requested_at' => 'immutable_datetime',
            'preaccepted_at' => 'immutable_datetime',
            'origin_decided_at' => 'immutable_datetime',
            'final_accepted_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'active_slot' => 'boolean',
            'client_version' => 'integer',
            'portfolio_version' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException('Las transferencias no se eliminan.'));
    }
}
