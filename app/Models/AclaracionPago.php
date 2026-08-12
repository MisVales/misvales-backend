<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class AclaracionPago extends Model
{
    use HasUuids;

    protected $table = 'payment_clarifications';

    protected $guarded = [];
}
