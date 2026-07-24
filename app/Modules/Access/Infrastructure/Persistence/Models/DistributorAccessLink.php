<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use App\Models\User;
use App\Modules\Access\Infrastructure\Persistence\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributorAccessLink extends Model
{
    use HasPublicUuid;

    /** @var list<string> */
    protected $fillable = ['user_id', 'external_request_id', 'external_distributor_id', 'branch_id', 'coordinator_user_id', 'authorized_by', 'initial_credit_line', 'authorized_at'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return ['initial_credit_line' => 'decimal:2', 'authorized_at' => 'immutable_datetime'];
    }
}
