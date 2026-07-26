<?php

declare(strict_types=1);

namespace App\Modules\Mobility\Infrastructure\Persistence\Models;

use App\Modules\Mobility\Infrastructure\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $branch_change_id
 * @property string $client_id
 * @property string $origin_distributor_id
 * @property string|null $destination_distributor_id
 * @property string|null $administrative_reassignment_id
 * @property string $status
 */
final class BranchChangeClientItem extends Model
{
    use UsesUuidPrimaryKey;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['completed_at' => 'immutable_datetime'];
    }
}
