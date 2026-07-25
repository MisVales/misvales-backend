<?php

declare(strict_types=1);

namespace App\Modules\Client\Persistence\Models;

use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Client\Domain\Assignments\AssignmentType;
use App\Modules\Client\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tramo histórico de responsabilidad de una distribuidora sobre el cliente.
 *
 * @property string $id
 * @property string $client_id
 * @property string $distributor_id
 * @property int $branch_id_snapshot
 * @property AssignmentType $assignment_type
 * @property CarbonImmutable $effective_from
 * @property ?CarbonImmutable $effective_to
 */
final class ClientDistributorAssignment extends Model
{
    use UsesUuidPrimaryKey;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id_snapshot');
    }

    protected function casts(): array
    {
        return [
            'assignment_type' => AssignmentType::class,
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'active_slot' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }
}
