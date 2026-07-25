<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use App\Modules\DistributorOnboarding\Domain\Verification\VisitResult;
use App\Modules\DistributorOnboarding\Persistence\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Visita física única asociada con la asignación vigente.
 *
 * @property int $id
 * @property string $public_id
 * @property CarbonImmutable $started_at
 * @property VisitResult|null $result
 * @property CarbonImmutable|null $completed_at
 * @property int $lock_version
 */
final class VerificationVisit extends Model
{
    use HasPublicId;

    /** @var list<string> */
    protected $guarded = [
        'id', 'public_id', 'application_id', 'assignment_id', 'verifier_user_id',
        'started_at', 'completed_at', 'result', 'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'result' => VisitResult::class,
            'observations' => 'encrypted',
            'device_context' => 'encrypted',
            'lock_version' => 'integer',
        ];
    }
}
