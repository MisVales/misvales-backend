<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $report_run_id
 * @property int $block_number
 * @property int $row_count
 * @property array<string, mixed> $payload_protected
 * @property string $payload_hash
 */
final class ReportRunResult extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['report_run_id', 'block_number', 'row_count', 'payload_protected', 'payload_hash', 'expires_at'];

    /** @return BelongsTo<ReportRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ReportRun::class, 'report_run_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload_protected' => 'encrypted:array',
            'expires_at' => 'immutable_datetime',
            'row_count' => 'integer',
            'block_number' => 'integer',
        ];
    }
}
