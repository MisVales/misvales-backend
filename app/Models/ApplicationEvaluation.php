<?php

namespace App\Models;

use App\Enums\ApplicationEvaluationResult;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationEvaluation extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'application_evaluations';

    protected $fillable = [
        'application_id', 'verification_visit_id', 'reason',
        'evaluation_payload', 'evaluated_by', 'evaluated_at',
    ];

    protected $casts = [
        'result' => ApplicationEvaluationResult::class,
        'evaluation_payload' => 'array',
        'evaluated_at' => 'datetime',
    ];

    public function application()
    {
        return $this->belongsTo(DistributorApplication::class, 'application_id');
    }

    public function visit()
    {
        return $this->belongsTo(VerificationVisit::class, 'verification_visit_id');
    }

    public function coordinator()
    {
        return $this->belongsTo(User::class, 'evaluated_by');
    }
}
