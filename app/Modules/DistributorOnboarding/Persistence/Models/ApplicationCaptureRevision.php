<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use App\Modules\DistributorOnboarding\Domain\Expedients\ExpedientSection;
use App\Modules\DistributorOnboarding\Persistence\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** Revisión inmutable de una sección capturada. */
final class ApplicationCaptureRevision extends Model
{
    use HasPublicId;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return [
            'section' => ExpedientSection::class,
            'previous_value' => 'encrypted',
            'new_value' => 'encrypted',
            'recorded_at' => 'immutable_datetime',
        ];
    }
}
