<?php

namespace App\Modules\Access\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessedDomainEvent extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['event_type', 'event_key', 'processed_at'];

    protected function casts(): array
    {
        return ['processed_at' => 'immutable_datetime'];
    }
}
