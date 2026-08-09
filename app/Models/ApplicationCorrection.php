<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Enums\ApplicationCorrectionSection;

class ApplicationCorrection extends Model {
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
    use HasUuids;

    protected $table = 'application_corrections';

    protected $fillable = [
        'application_id', 'verification_visit_id', 'section', 'field_path',
        'previous_value_payload', 'new_value_payload', 'reason', 
        'corrected_by', 'corrected_at'
    ];

    protected $casts = [
        'section' => ApplicationCorrectionSection::class,
        'previous_value_payload' => 'encrypted',
        'new_value_payload' => 'encrypted',
        'corrected_at' => 'datetime',
    ];

    protected $hidden = [
        'previous_value_payload', 'new_value_payload'
    ];

    public function application() { return $this->belongsTo(DistributorApplication::class, 'application_id'); }
    public function visit() { return $this->belongsTo(VerificationVisit::class, 'verification_visit_id'); }
    public function coordinator() { return $this->belongsTo(User::class, 'corrected_by'); }
}
