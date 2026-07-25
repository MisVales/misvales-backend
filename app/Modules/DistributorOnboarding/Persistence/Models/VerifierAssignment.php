<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use App\Models\User;
use App\Modules\DistributorOnboarding\Persistence\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asignación histórica de un verificador a una solicitud.
 *
 * @property int $id
 * @property string $public_id
 * @property int $lock_version
 */
final class VerifierAssignment extends Model
{
    use HasPublicId;

    protected $table = 'application_verifier_assignments';

    /** @var list<string> */
    protected $guarded = [
        'id', 'public_id', 'application_id', 'verifier_user_id', 'branch_id',
        'assigned_by', 'assigned_at', 'ended_at', 'active_slot', 'lock_version',
    ];

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verifier_user_id');
    }

    protected function casts(): array
    {
        return [
            'assigned_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'active_slot' => 'boolean',
            'lock_version' => 'integer',
            'reason' => 'encrypted',
        ];
    }
}
