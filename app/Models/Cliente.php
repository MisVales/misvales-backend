<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Cliente extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'clients';

    protected $fillable = [
        'client_number', 'first_name', 'first_last_name', 'second_last_name',
        'birth_date', 'birth_place', 'birth_state', 'birth_city',
        'official_id_type', 'official_id_media_id', 'created_by',
    ];

    protected $hidden = [
        'curp_ciphertext', 'curp_hmac', 'rfc_ciphertext', 'rfc_hmac',
        'official_id_number_ciphertext', 'official_id_number_hmac',
    ];

    protected function casts(): array
    {
        return ['birth_date' => 'immutable_date', 'lock_version' => 'integer'];
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function domicilios(): HasMany
    {
        return $this->hasMany(DomicilioCliente::class, 'client_id');
    }

    public function domicilioVigente(): HasOne
    {
        return $this->hasOne(DomicilioCliente::class, 'client_id')->where('is_current', true)->whereNull('ends_at');
    }

    public function cuentasBancarias(): HasMany
    {
        return $this->hasMany(CuentaBancariaCliente::class, 'client_id');
    }

    public function cuentaBancariaVigente(): HasOne
    {
        return $this->hasOne(CuentaBancariaCliente::class, 'client_id')->where('is_current', true)->whereNull('ends_at');
    }

    public function asignacionesDistribuidora(): HasMany
    {
        return $this->hasMany(AsignacionClienteDistribuidora::class, 'client_id');
    }

    public function asignacionVigente(): HasOne
    {
        return $this->hasOne(AsignacionClienteDistribuidora::class, 'client_id')->whereNull('ends_at');
    }

    public function movimientosCartera(): HasMany
    {
        return $this->hasMany(MovimientoCarteraCliente::class, 'client_id');
    }
}
