<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use App\Modules\DistributorOnboarding\Persistence\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Vehículo declarado; su detalle permanece cifrado mientras se aprueba la estructura exacta.
 *
 * @property string $public_id
 * @property string $declared_details
 */
final class ApplicationVehicle extends Model
{
    use HasPublicId;

    /** @var list<string> */
    protected $guarded = ['id', 'public_id', 'application_id', 'retired_at'];

    protected function casts(): array
    {
        return [
            'declared_details' => 'encrypted',
            'retired_at' => 'immutable_datetime',
        ];
    }
}
