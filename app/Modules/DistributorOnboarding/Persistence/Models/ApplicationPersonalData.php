<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Sección personal única del expediente; los valores sensibles se cifran en reposo.
 *
 * @property int $lock_version
 * @property CarbonImmutable|null $birth_date
 */
final class ApplicationPersonalData extends Model
{
    /** @var list<string> */
    protected $guarded = ['id', 'application_id', 'lock_version'];

    protected function casts(): array
    {
        return [
            'first_name' => 'encrypted',
            'paternal_surname' => 'encrypted',
            'maternal_surname' => 'encrypted',
            'curp' => 'encrypted',
            'rfc' => 'encrypted',
            'birth_date' => 'immutable_date',
            'birth_place' => 'encrypted',
            'birth_state' => 'encrypted',
            'birth_city' => 'encrypted',
            'declared_address' => 'encrypted',
            'lock_version' => 'integer',
        ];
    }
}
