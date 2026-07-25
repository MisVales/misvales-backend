<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationStatus;
use App\Modules\DistributorOnboarding\Persistence\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Historial de inserción única de las transiciones de M04.
 *
 * @property string $public_id
 * @property string $action
 * @property ApplicationStatus|null $previous_status
 * @property ApplicationStatus $new_status
 * @property string $actor_role
 * @property string|null $reason
 * @property string|null $result
 * @property int $application_version
 * @property CarbonImmutable $occurred_at
 */
final class ApplicationStatusHistory extends Model
{
    use HasPublicId;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'previous_status' => ApplicationStatus::class,
            'new_status' => ApplicationStatus::class,
            'reason' => 'encrypted',
            'application_version' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
