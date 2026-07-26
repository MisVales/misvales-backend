<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Reporting\Domain\Enums\ReportCode;
use App\Modules\Reporting\Domain\Enums\ReportRunStatus;
use App\Modules\Reporting\Domain\Enums\ReportScopeType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Technical metadata only; business data remains in owner modules.
 *
 * @property string $id
 * @property string $run_number
 * @property ReportCode $report_code
 * @property int $contract_version
 * @property ReportRunStatus $status
 * @property int $requested_by
 * @property string $requested_role
 * @property ReportScopeType $scope_type
 * @property array<string, mixed> $scope_snapshot
 * @property array<string, mixed> $filters_json
 * @property string $filters_hash
 * @property string $idempotency_key
 * @property CarbonInterface $queued_at
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $failed_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $as_of
 * @property int|null $row_count
 * @property string|null $result_location
 * @property string|null $error_code
 * @property string $correlation_id
 * @property-read User $requester
 */
final class ReportRun extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'id', 'run_number', 'report_code', 'contract_version', 'status', 'requested_by',
        'requested_role', 'scope_type', 'scope_snapshot', 'filters_json', 'filters_hash',
        'idempotency_key', 'queued_at', 'started_at', 'completed_at', 'failed_at',
        'expires_at', 'as_of', 'row_count', 'result_location', 'error_code', 'correlation_id',
    ];

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return HasMany<ReportRunResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(ReportRunResult::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'report_code' => ReportCode::class,
            'status' => ReportRunStatus::class,
            'scope_type' => ReportScopeType::class,
            'scope_snapshot' => 'array',
            'filters_json' => 'array',
            'queued_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'as_of' => 'immutable_datetime',
            'row_count' => 'integer',
            'contract_version' => 'integer',
        ];
    }
}
