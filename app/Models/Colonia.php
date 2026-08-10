<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Colonia extends Model
{
    use HasFactory;

    protected $fillable = ['codigo_postal_id', 'name', 'settlement_type'];

    public function codigoPostal()
    {
        return $this->belongsTo(CodigoPostal::class);
    }
}
