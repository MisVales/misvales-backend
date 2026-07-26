<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Infrastructure\Persistence\Models;

use App\Modules\Mobility\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $administrative_reassignment_id
 * @property string $client_id
 * @property string $origin_distributor_id
 * @property string $destination_distributor_id
 * @property string $origin_assignment_id
 * @property string|null $destination_assignment_id
 * @property string $total_due_snapshot
 * @property string $overdue_snapshot
 * @property int $client_version
 * @property int $portfolio_version
 * @property string $status
 * @property string|null $error_code
 */
final class AdministrativeReassignmentItem extends Model
{
    use UsesUuidPrimaryKey;

    protected $guarded = ['*'];

    /** @return BelongsTo<AdministrativeReassignment, $this> */
    public function reassignment(): BelongsTo
    {
        return $this->belongsTo(AdministrativeReassignment::class, 'administrative_reassignment_id');
    }

    protected function casts(): array
    {
        return [
            'total_due_snapshot' => 'decimal:4',
            'overdue_snapshot' => 'decimal:4',
            'client_version' => 'integer',
            'portfolio_version' => 'integer',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
