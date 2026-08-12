<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SolicitudConciliacionManual extends Model
{
    use HasUuids;

    protected $table = 'manual_reconciliation_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['before_snapshot' => 'array', 'after_snapshot' => 'array', 'authorized_at' => 'immutable_datetime', 'executed_at' => 'immutable_datetime'];
    }
}
