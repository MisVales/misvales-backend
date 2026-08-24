<?php

namespace App\Models;

use App\Enums\VerificationVisitResult;
use App\Enums\VerificationVisitStatus;
use App\Models\Concerns\HasOptimisticLocking;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class VerificationVisit extends Model
{
    use HasFactory;
    use HasOptimisticLocking, HasUuids;

    protected $table = 'verification_visits';

    protected $fillable = [
        'application_id', 'verifier_id', 'assigned_by', 'assigned_at', 'scheduled_for',
        'started_at', 'completed_at', 'visited_at',
        'observations', 'differences_payload', 'latitude', 'longitude', 'location_accuracy_meters',
    ];

    protected $casts = [
        'status' => VerificationVisitStatus::class,
        'result' => VerificationVisitResult::class,
        'assigned_at' => 'datetime',
        'scheduled_for' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'visited_at' => 'datetime',
        'differences_payload' => 'array',
        'latitude' => 'string',
        'longitude' => 'string',
        'location_accuracy_meters' => 'string',
    ];

    public function application()
    {
        return $this->belongsTo(DistributorApplication::class, 'application_id');
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verifier_id');
    }

    public function coordinator()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function mediaFiles(): BelongsToMany
    {
        return $this->belongsToMany(MediaFile::class, 'media_file_bindings', 'owner_id', 'media_file_id')
            ->wherePivot('owner_type', 'verification_visit')
            ->withPivot(['purpose', 'created_by'])
            ->withTimestamps();
    }

    public function corrections()
    {
        return $this->hasMany(ApplicationCorrection::class, 'verification_visit_id');
    }
}
