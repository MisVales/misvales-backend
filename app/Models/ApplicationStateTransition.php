<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Enums\ApplicationStatus;

class ApplicationStateTransition extends Model {
    use HasUuids;

    protected $table = 'application_state_transitions';

    protected $fillable = [
        'application_id', 'from_status', 'to_status', 'user_id', 'reason'
    ];

    protected $casts = [
        'from_status' => ApplicationStatus::class,
        'to_status' => ApplicationStatus::class,
    ];
}
