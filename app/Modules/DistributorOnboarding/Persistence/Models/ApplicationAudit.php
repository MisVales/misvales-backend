<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use App\Models\User;
use App\Modules\DistributorOnboarding\Domain\Applications\ApplicationStatus;
use App\Modules\DistributorOnboarding\Persistence\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Auditoría funcional de inserción única con valores sensibles cifrados.
 *
 * @property string $public_id
 * @property string $event_type
 * @property string|null $actor_role
 * @property string $entity_type
 * @property string|null $entity_public_id
 * @property ApplicationStatus|null $previous_status
 * @property ApplicationStatus|null $new_status
 * @property string|null $reason
 * @property string|null $result
 * @property int $application_version
 * @property CarbonImmutable $occurred_at
 * @property User|null $requester
 */
final class ApplicationAudit extends Model
{
    use HasPublicId;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id', 'public_id'];

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    protected function casts(): array
    {
        return [
            'previous_status' => ApplicationStatus::class,
            'new_status' => ApplicationStatus::class,
            'protected_previous_value' => 'encrypted',
            'protected_new_value' => 'encrypted',
            'reason' => 'encrypted',
            'application_version' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'business_occurred_at' => 'immutable_datetime',
        ];
    }
}
