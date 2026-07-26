<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class VoucherAuditModel extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'voucher_audits';

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
        self::deleting(fn (): never => throw new LogicException('La auditoría de vales no se elimina.'));
    }
}
