<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use App\Modules\DistributorOnboarding\Persistence\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Referencia laboral declarada y protegida.
 *
 * @property string $public_id
 * @property string $name
 * @property string|null $contact
 * @property string|null $declared_details
 */
final class ApplicationLaborReference extends Model
{
    use HasPublicId;

    /** @var list<string> */
    protected $guarded = ['id', 'public_id', 'application_id', 'retired_at'];

    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'contact' => 'encrypted',
            'declared_details' => 'encrypted',
            'retired_at' => 'immutable_datetime',
        ];
    }
}
