<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use App\Models\User;
use App\Modules\DistributorOnboarding\Domain\Expedients\ExpedientSection;
use App\Modules\DistributorOnboarding\Persistence\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Corrección de inserción única que conserva el valor original.
 *
 * @property string $public_id
 * @property ExpedientSection $section
 * @property string $field_path
 * @property string $original_value
 * @property string $corrected_value
 * @property string $reason
 * @property CarbonImmutable $corrected_at
 */
final class ApplicationCorrection extends Model
{
    use HasPublicId;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id', 'public_id', 'application_id', 'difference_id', 'corrected_by', 'corrected_at', 'request_id'];

    /** @return BelongsTo<VerificationDifference, $this> */
    public function difference(): BelongsTo
    {
        return $this->belongsTo(VerificationDifference::class, 'difference_id');
    }

    /** @return BelongsTo<User, $this> */
    public function corrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    protected function casts(): array
    {
        return [
            'section' => ExpedientSection::class,
            'original_value' => 'encrypted',
            'corrected_value' => 'encrypted',
            'reason' => 'encrypted',
            'corrected_at' => 'immutable_datetime',
        ];
    }
}
