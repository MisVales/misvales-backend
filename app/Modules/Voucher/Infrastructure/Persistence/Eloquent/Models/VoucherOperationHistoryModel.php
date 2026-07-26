<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class VoucherOperationHistoryModel extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'voucher_operation_history';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'protected_context' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException('El historial de vales no se elimina.'));
    }
}
