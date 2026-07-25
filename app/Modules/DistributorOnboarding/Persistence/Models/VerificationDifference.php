<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use App\Modules\DistributorOnboarding\Domain\Expedients\ExpedientSection;
use App\Modules\DistributorOnboarding\Persistence\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Diferencia inmutable entre el valor declarado y el observado.
 *
 * @property int $id
 * @property string $public_id
 * @property ExpedientSection $section
 * @property string $field_path
 * @property string $declared_value
 * @property string $observed_value
 * @property string $description
 * @property string $classification_code
 * @property string|null $evidence_media_id
 * @property CarbonImmutable $recorded_at
 * @property CarbonImmutable|null $resolved_at
 */
final class VerificationDifference extends Model
{
    use HasPublicId;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id', 'public_id', 'application_id', 'visit_id', 'verifier_user_id', 'recorded_at', 'resolved_at'];

    protected function casts(): array
    {
        return [
            'section' => ExpedientSection::class,
            'declared_value' => 'encrypted',
            'observed_value' => 'encrypted',
            'description' => 'encrypted',
            'recorded_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
