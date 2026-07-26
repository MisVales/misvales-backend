<?php

namespace App\Models;

use App\Modules\Access\Domain\Accounts\AccountState;
use App\Modules\Access\Infrastructure\Persistence\Models\Branch;
use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use App\Modules\Access\Infrastructure\Persistence\Models\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $public_id
 * @property int $role_id
 * @property int|null $branch_id
 * @property AccountState $state
 * @property int $credential_version
 * @property int $context_version
 * @property-read Role|null $role
 * @property-read Branch|null $branch
 */
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasPublicUuid;
    use Notifiable;

    /** @var list<string> */
    protected $fillable = ['name', 'email'];

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'coordinator_id');
    }

    /**
     * Snapshot accessor retained for security audit/context consumers.
     */
    public function getRoleCodeAttribute(): ?string
    {
        $code = $this->relationLoaded('role')
            ? $this->getRelation('role')?->code
            : $this->role()->value('code');

        return $code instanceof \BackedEnum ? (string) $code->value : (is_string($code) ? $code : null);
    }

    /**
     * Public branch identifier used in API payloads and authorization bindings.
     */
    public function getBranchPublicIdAttribute(): ?string
    {
        $publicId = $this->relationLoaded('branch')
            ? $this->getRelation('branch')?->public_id
            : $this->branch()->value('public_id');

        return is_string($publicId) ? $publicId : null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'state' => AccountState::class,
            'context_version' => 'integer',
            'credential_version' => 'integer',
            'assignment_version' => 'integer',
            'invited_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'password_changed_at' => 'immutable_datetime',
            'mfa_enrolled_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'security_suspended_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            if ($user->isDirty('email')) {
                $user->email = trim((string) $user->email);
                $user->normalized_email = mb_strtolower((string) $user->email);
            }
        });
    }
}
