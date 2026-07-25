<?php

declare(strict_types=1);

namespace App\Modules\Credit\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CreditAuditEventModel extends Model
{
    use HasPublicUuid;

    protected $table = 'credit_audit_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before_state' => 'array',
            'after_state' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('La auditoría de crédito es inmutable.'));
        self::deleting(fn (): never => throw new LogicException('La auditoría de crédito es inmutable.'));
    }
}
