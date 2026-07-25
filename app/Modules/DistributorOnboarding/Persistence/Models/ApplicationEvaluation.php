<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use App\Modules\DistributorOnboarding\Domain\Decisions\CoordinatorDecision;
use App\Modules\DistributorOnboarding\Persistence\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Evaluación final e inmutable del coordinador.
 *
 * @property string $public_id
 * @property CoordinatorDecision $decision
 * @property string $reason
 * @property int $application_version
 * @property CarbonImmutable $decided_at
 */
final class ApplicationEvaluation extends Model
{
    use HasPublicId;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id', 'public_id', 'application_id', 'coordinator_user_id', 'branch_id', 'visit_id', 'application_version', 'decided_at'];

    protected function casts(): array
    {
        return [
            'decision' => CoordinatorDecision::class,
            'reason' => 'encrypted',
            'application_version' => 'integer',
            'decided_at' => 'immutable_datetime',
        ];
    }
}
