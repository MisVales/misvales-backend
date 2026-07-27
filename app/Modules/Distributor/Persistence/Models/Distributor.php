<?php

namespace App\Modules\Distributor\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Distributor extends Model
{
    use HasUuids;

    protected $table = 'distributors';

    protected $fillable = [
        'distributor_number',
        'onboarding_application_id',
        'user_id',
        'branch_id',
        'status',
        'activated_at',
        'activated_by',
        'activation_operation_id',
        'lock_version',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'lock_version' => 'integer',
    ];
}
