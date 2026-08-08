<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatosPersonalesSolicitud extends Model
{
    use HasUuids;

    protected $table = 'application_personal_data';

    protected $fillable = [
        'application_id', 'first_name', 'first_last_name', 'second_last_name',
        'birth_date', 'birth_place', 'birth_state', 'birth_city', 'email',
        'phone_number', 'official_id_type',
    ];

    protected $hidden = [
        'curp_ciphertext', 'curp_hmac', 'rfc_ciphertext', 'rfc_hmac',
        'official_id_number_ciphertext', 'official_id_number_hmac',
    ];

    protected function casts(): array
    {
        return ['birth_date' => 'immutable_date'];
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudDistribuidora::class, 'application_id');
    }
}
