<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Payment\Domain\Enums\ClarificationStatus;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/** Persistencia interna de una aclaración de pago. */
final class ClarificationModel extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'payment_clarifications';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reported_amount' => 'decimal:4',
            'reported_date' => 'immutable_date',
            'status' => ClarificationStatus::class,
            'reviewed_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }
}
