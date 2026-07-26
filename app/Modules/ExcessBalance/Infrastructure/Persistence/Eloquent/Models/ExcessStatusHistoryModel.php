<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

final class ExcessStatusHistoryModel extends Model
{
    use UsesUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $table = 'excess_status_history';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amounts_before' => 'array',
            'amounts_after' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
