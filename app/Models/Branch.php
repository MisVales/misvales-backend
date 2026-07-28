<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $fillable = [
        'public_id',
        'name',
        'is_headquarters',
        'city',
        'is_active',
    ];

    protected $hidden = [
        'id',
    ];
}
