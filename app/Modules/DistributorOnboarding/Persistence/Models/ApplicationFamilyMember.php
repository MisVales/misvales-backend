<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use App\Modules\DistributorOnboarding\Persistence\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Integrante familiar declarado; no se elimina físicamente.
 *
 * @property string $public_id
 * @property string $relationship_code
 * @property string $name
 * @property int|null $age
 * @property string|null $school
 */
final class ApplicationFamilyMember extends Model
{
    use HasPublicId;

    /** @var list<string> */
    protected $guarded = ['id', 'public_id', 'application_id', 'retired_at'];

    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'school' => 'encrypted',
            'age' => 'integer',
            'retired_at' => 'immutable_datetime',
        ];
    }
}
