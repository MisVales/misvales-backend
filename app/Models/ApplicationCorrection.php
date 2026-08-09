<?php

namespace App\Models;

use App\Enums\ApplicationCorrectionSection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicationCorrection extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'application_corrections';

    protected $fillable = [
        'application_id', 'verification_visit_id', 'section', 'field_path',
        'previous_value_payload', 'new_value_payload', 'reason',
        'corrected_by', 'corrected_at',
    ];

    protected $casts = [
        'section' => ApplicationCorrectionSection::class,
        'previous_value_payload' => 'encrypted:json',
        'new_value_payload' => 'encrypted:json',
        'corrected_at' => 'datetime',
    ];

    protected $hidden = [
        'previous_value_payload', 'new_value_payload',
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
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
