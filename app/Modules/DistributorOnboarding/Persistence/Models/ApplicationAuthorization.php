<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use App\Modules\DistributorOnboarding\Domain\Decisions\ManagerDecision;
use App\Modules\DistributorOnboarding\Persistence\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Decisión gerencial final e inmutable.
 *
 * @property int $id
 * @property string $public_id
 * @property ManagerDecision $decision
 * @property string|null $initial_credit_line
 * @property string $reason
 * @property int $application_version
 * @property CarbonImmutable $decided_at
 */
final class ApplicationAuthorization extends Model
{
    use HasPublicId;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [
        'id', 'public_id', 'application_id', 'manager_user_id', 'manager_role',
        'manager_branch_id', 'application_version', 'decided_at', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'decision' => ManagerDecision::class,
            'initial_credit_line' => 'decimal:4',
            'reason' => 'encrypted',
            'application_version' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }
}
