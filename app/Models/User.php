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

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    use HasPublicUuid;

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password'];

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

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'state' => AccountState::class,
            'context_version' => 'integer',
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
                $user->email = trim($user->email);
                $user->normalized_email = mb_strtolower($user->email);
            }
        });
    }
}
