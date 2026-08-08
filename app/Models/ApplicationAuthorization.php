<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Enums\ApplicationAuthorizationDecision;

class ApplicationAuthorization extends Model {
    use \Illuminate\Database\Eloquent\Factories\HasFactory;
    use HasUuids;

    protected $table = 'application_authorizations';

    protected $fillable = [
        'application_id', 'initial_credit_line_amount', 
        'reason', 'authorized_by', 'authorized_at'
    ];

    protected $casts = [
        'decision' => ApplicationAuthorizationDecision::class,
        'initial_credit_line_amount' => 'string',
        'authorized_at' => 'datetime',
    ];

    public function application() { return $this->belongsTo(DistributorApplication::class, 'application_id'); }
    public function manager() { return $this->belongsTo(User::class, 'authorized_by'); }
}
