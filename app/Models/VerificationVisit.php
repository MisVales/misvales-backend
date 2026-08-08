<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Enums\VerificationVisitStatus;
use App\Enums\VerificationVisitResult;
use App\Traits\HasOptimisticLocking;

class VerificationVisit extends Model {
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
    use HasUuids, HasOptimisticLocking;

    protected $table = 'verification_visits';

    protected $fillable = [
        'application_id', 'verifier_id', 'assigned_by', 'assigned_at',
        'started_at', 'completed_at', 'visited_at',
        'observations', 'differences_payload', 'latitude', 'longitude', 'location_accuracy_meters'
    ];

    protected $casts = [
        'status' => VerificationVisitStatus::class,
        'result' => VerificationVisitResult::class,
        'assigned_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'visited_at' => 'datetime',
        'differences_payload' => 'array',
        'latitude' => 'string',
        'longitude' => 'string',
        'location_accuracy_meters' => 'string',
    ];

    public function application() { return $this->belongsTo(DistributorApplication::class, 'application_id'); }
    public function verifier() { return $this->belongsTo(User::class, 'verifier_id'); }
    public function coordinator() { return $this->belongsTo(User::class, 'assigned_by'); }
    public function mediaFiles() { return $this->hasMany(MediaFile::class, 'verification_visit_id'); }
    public function corrections() { return $this->hasMany(ApplicationCorrection::class, 'verification_visit_id'); }
}
