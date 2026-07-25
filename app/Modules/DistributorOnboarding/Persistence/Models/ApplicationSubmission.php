<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use App\Modules\DistributorOnboarding\Persistence\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** Huella de la versión lógica enviada a revisión. */
final class ApplicationSubmission extends Model
{
    use HasPublicId;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'application_version' => 'integer',
            'submitted_at' => 'immutable_datetime',
        ];
    }
}
