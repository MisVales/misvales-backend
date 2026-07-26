<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Voucher\Domain\Enums\DataChangeOperation;
use App\Modules\Voucher\Domain\Enums\DataChangeRequestStatus;
use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property string $voucher_id
 * @property string $client_id
 * @property int $branch_id
 * @property int $requested_by
 * @property DataChangeOperation $operation
 * @property list<string> $authorized_fields
 * @property string $reason
 * @property DataChangeRequestStatus $status
 * @property int|null $decided_by
 * @property int $lock_version
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $used_at
 * @property CarbonImmutable|null $expired_at
 * @property array<string, int> $target_lock_versions
 */
final class DataChangeRequestModel extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'data_change_requests';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'operation' => DataChangeOperation::class,
            'authorized_fields' => 'array',
            'status' => DataChangeRequestStatus::class,
            'target_lock_versions' => 'array',
            'lock_version' => 'integer',
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException('Las solicitudes de modificación no se eliminan.'));
    }
}
