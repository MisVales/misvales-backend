<?php

namespace App\Models;

use App\Enums\ApplicationAuthorizationDecision;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationAuthorization extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'application_authorizations';

    protected $fillable = [
        'application_id',
        'reason', 'authorized_by', 'authorized_at',
    ];

    protected $casts = [
        'decision' => ApplicationAuthorizationDecision::class,
        'authorized_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(DistributorApplication::class, 'application_id');
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }
}
