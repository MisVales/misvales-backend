<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use App\Modules\DistributorOnboarding\Domain\Expedients\AssetLiabilityType;
use App\Modules\DistributorOnboarding\Persistence\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Bien, préstamo o compromiso activo declarado.
 *
 * @property string $public_id
 * @property AssetLiabilityType $entry_type
 * @property string $description
 * @property string|null $amount
 */
final class ApplicationAssetLiability extends Model
{
    use HasPublicId;

    protected $table = 'application_assets_liabilities';

    /** @var list<string> */
    protected $guarded = ['id', 'public_id', 'application_id', 'retired_at'];

    protected function casts(): array
    {
        return [
            'entry_type' => AssetLiabilityType::class,
            'description' => 'encrypted',
            'amount' => 'decimal:4',
            'retired_at' => 'immutable_datetime',
        ];
    }
}
