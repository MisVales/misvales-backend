<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use App\Modules\DistributorOnboarding\Persistence\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Domicilio estructurado con contenido sensible cifrado.
 *
 * @property string $public_id
 * @property string $structured_address
 * @property string|null $housing_type_code
 * @property string|null $tenure_code
 * @property string|null $financing_code
 * @property string|null $dimensions
 */
final class ApplicationResidence extends Model
{
    use HasPublicId;

    /** @var list<string> */
    protected $guarded = ['id', 'public_id', 'application_id', 'retired_at'];

    protected function casts(): array
    {
        return [
            'structured_address' => 'encrypted',
            'dimensions' => 'encrypted',
            'retired_at' => 'immutable_datetime',
        ];
    }
}
