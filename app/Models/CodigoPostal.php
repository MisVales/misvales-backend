<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CodigoPostal extends Model
{
    use HasFactory;

    protected $table = 'codigos_postales';

    protected $fillable = ['municipio_id', 'code'];

    public function municipio()
    {
        return $this->belongsTo(Municipio::class);
    }

    public function colonias()
    {
        return $this->hasMany(Colonia::class);
    }
}
