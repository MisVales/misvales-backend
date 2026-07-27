<?php

namespace App\Modules\Audit\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    use HasUuids;

    protected $table = 'audit_events';

    // Deshabilitar `updated_at` (Inmutabilidad obligatoria)
    const UPDATED_AT = null;

    protected $fillable = [
        'event_code',
        'event_version',
        'category',
        'occurred_at',
        'business_datetime',
        'requester_user_id',
        'authorizer_user_id',
        'executor_user_id',
        'process_code',
        'role_snapshot',
        'branch_id',
        'session_id',
        'device_id',
        'ip_address',
        'user_agent_summary',
        'subject_type',
        'subject_id',
        'subject_public_number',
        'action',
        'result',
        'changed_fields',
        'before_data',
        'after_data',
        'reason_code',
        'reason_text',
        'rule_code',
        'rule_version',
        'evidence_file_ids',
        'request_id',
        'trace_id',
        'correlation_id',
        'causation_id',
        'idempotency_key_hash',
        'metadata',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'business_datetime' => 'datetime',
        'changed_fields' => 'json',
        'before_data' => 'json',
        'after_data' => 'json',
        'evidence_file_ids' => 'json',
        'metadata' => 'json',
    ];
}
