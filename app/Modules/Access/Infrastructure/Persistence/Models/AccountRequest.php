<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Modules\Access\Domain\Accounts\AccountRequestState;
use App\Modules\Access\Domain\Accounts\AccountRequestType;
use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * @property AccountRequestType $type
 * @property AccountRequestState $state
 */
class AccountRequest extends Model
{
    use HasPublicUuid;

    /** @var list<string> */
    protected $fillable = ['type', 'target_user_id', 'target_email', 'target_name', 'requested_role_id', 'branch_id', 'requested_by', 'reason', 'idempotency_key'];

    /** @var list<string> */
    protected $hidden = ['id', 'target_user_id', 'requested_role_id', 'branch_id', 'requested_by', 'decided_by', 'result_user_id', 'idempotency_key'];

    protected function casts(): array
    {
        return ['type' => AccountRequestType::class, 'state' => AccountRequestState::class, 'decided_at' => 'immutable_datetime'];
    }
}
