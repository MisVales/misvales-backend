<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use App\Modules\DistributorOnboarding\Persistence\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * Crédito comercial declarado con importe decimal exacto.
 *
 * @property string $public_id
 * @property string $company_name
 * @property string|null $credit_limit
 * @property string|null $proof_media_id
 */
final class ApplicationCommercialCredit extends Model
{
    use HasPublicId;

    /** @var list<string> */
    protected $guarded = ['id', 'public_id', 'application_id', 'retired_at'];

    protected function casts(): array
    {
        return [
            'company_name' => 'encrypted',
            'credit_limit' => 'decimal:4',
            'retired_at' => 'immutable_datetime',
        ];
    }
}
