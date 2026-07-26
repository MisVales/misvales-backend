<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class ReportQueryEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'actor_id', 'actor_role', 'report_code', 'scope_type', 'filters_hash',
        'outcome', 'rows_returned', 'session_id', 'run_id', 'correlation_id',
        'error_code', 'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'rows_returned' => 'integer'];
    }
}
