<?php

declare(strict_types=1);

namespace App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Payment\Domain\Enums\ManualReconciliationStatus;
use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/** Persistencia interna de una solicitud de conciliación manual. */
final class ManualReconciliationModel extends Model
{
    use UsesUuidPrimaryKey;

    protected $table = 'manual_reconciliations';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ManualReconciliationStatus::class,
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'authorization_expires_at' => 'immutable_datetime',
            'authorization_consumed_at' => 'immutable_datetime',
            'executed_at' => 'immutable_datetime',
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
            'lock_version' => 'integer',
        ];
    }
}
