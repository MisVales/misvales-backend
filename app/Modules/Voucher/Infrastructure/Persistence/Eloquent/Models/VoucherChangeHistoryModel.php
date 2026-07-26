<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Voucher\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Hidden(['previous_value_encrypted', 'new_value_encrypted'])]
final class VoucherChangeHistoryModel extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'voucher_change_history';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::deleting(fn (): never => throw new LogicException('El historial de cambios no se elimina.'));
    }
}
