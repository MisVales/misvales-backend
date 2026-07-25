<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use App\Modules\DistributorOnboarding\Persistence\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Referencia familiar declarada y protegida.
 *
 * @property string $public_id
 * @property string $relationship_code
 * @property string $name
 * @property string|null $phone
 */
final class ApplicationFamilyReference extends Model
{
    use HasPublicId;

    /** @var list<string> */
    protected $guarded = ['id', 'public_id', 'application_id', 'retired_at'];

    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'phone' => 'encrypted',
            'retired_at' => 'immutable_datetime',
        ];
    }
}
