<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Modules\Access\Domain\Accounts\AccountRequestState;
use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

class AccountRequest extends Model
{
    use HasPublicUuid;

    /** @var list<string> */
    protected $fillable = ['type', 'target_user_id', 'target_email', 'requested_role_id', 'branch_id', 'requested_by', 'reason', 'idempotency_key'];

    protected function casts(): array
    {
        return ['state' => AccountRequestState::class, 'decided_at' => 'immutable_datetime'];
    }
}
