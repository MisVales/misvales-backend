<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['metadata'])]
final class SecurityEvent extends Model
{
    use HasPublicUuid;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'before_state' => 'array',
            'after_state' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::creating(function (self $event): void {
            $event->rule ??= $event->rule_code;
            $event->rule_code ??= $event->rule;
            $event->scope ??= $event->branch_id === null ? 'GLOBAL' : 'BRANCH';
        });
    }
}
