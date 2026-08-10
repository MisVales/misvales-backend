<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Municipio extends Model
{
    use HasFactory;

    protected $fillable = ['estado_id', 'name'];

    public function estado()
    {
        return $this->belongsTo(Estado::class);
    }

    public function codigosPostales()
    {
        return $this->hasMany(CodigoPostal::class);
    }
}
