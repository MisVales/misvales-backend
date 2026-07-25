<?php

declare(strict_types=1);

namespace App\Modules\Client\Persistence\Models;

use App\Modules\Client\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Confirmación versionada de saldo usada exclusivamente por M15.
 *
 * @property string $id
 * @property string $client_id
 * @property string $distributor_id
 * @property string $assignment_id
 * @property string $total_balance
 * @property ?string $overdue_balance
 * @property int $portfolio_version
 * @property int $confirmed_by
 * @property CarbonImmutable $confirmed_at
 */
final class ClientPortfolioConfirmation extends Model
{
    use UsesUuidPrimaryKey;

    public const CREATED_AT = 'confirmed_at';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'total_balance' => 'decimal:4',
            'overdue_balance' => 'decimal:4',
            'portfolio_version' => 'integer',
            'confirmed_at' => 'immutable_datetime',
        ];
    }
}
