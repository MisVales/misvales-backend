<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $event_id
 * @property string $event_name
 * @property string|null $aggregate_id
 * @property string|null $report_code
 * @property int|null $actor_id
 * @property string|null $scope_type
 * @property string $correlation_id
 * @property array<string, mixed> $payload
 * @property CarbonInterface $occurred_at
 * @property CarbonInterface|null $published_at
 * @property int $attempts
 * @property string|null $last_error
 */
final class ReportOutboxEvent extends Model
{
    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'event_id', 'event_name', 'aggregate_id', 'report_code', 'actor_id',
        'scope_type', 'correlation_id', 'payload', 'occurred_at', 'published_at',
        'attempts', 'last_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }
}
