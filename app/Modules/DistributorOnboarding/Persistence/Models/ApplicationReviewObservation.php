<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use App\Modules\DistributorOnboarding\Persistence\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** Observación histórica de revisión del coordinador. */
final class ApplicationReviewObservation extends Model
{
    use HasPublicId;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'observation' => 'encrypted',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
