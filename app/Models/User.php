<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $email
 * @property string $normalized_email
 * @property string|null $password
 * @property string $state
 * @property int $credential_version
 * @property int $context_version
 */
#[Fillable(['public_id', 'name', 'email', 'normalized_email', 'password', 'state'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'activated_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'mfa_enrolled_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
