<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Modules\Access\Domain\MFA\MfaType;
use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores an MFA credential without serializing sensitive factor material.
 *
 * @property int $id
 * @property int $user_id
 * @property MfaType $type
 * @property string $state
 * @property string|null $encrypted_secret
 */
#[Hidden(['public_key', 'encrypted_secret', 'metadata'])]
final class MfaCredential extends Model
{
    use HasPublicUuid;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => MfaType::class,
            'metadata' => 'array',
            'registered_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
