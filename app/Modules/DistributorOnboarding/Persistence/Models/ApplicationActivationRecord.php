<?php

declare(strict_types=1);

namespace App\Modules\DistributorOnboarding\Persistence\Models;

use App\Modules\DistributorOnboarding\Persistence\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Resultado inmutable e idempotente del aprovisionamiento intermodular.
 *
 * @property string $public_id
 * @property string $distributor_id
 * @property string $distributor_number
 * @property string $account_id
 * @property string $organization_assignment_id
 * @property string $credit_line_id
 * @property string $initial_credit_line
 * @property CarbonImmutable $activated_at
 */
final class ApplicationActivationRecord extends Model
{
    use HasPublicId;

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id', 'public_id', 'application_id', 'authorization_id', 'operation_key', 'activated_at'];

    protected function casts(): array
    {
        return [
            'initial_credit_line' => 'decimal:4',
            'activated_at' => 'immutable_datetime',
        ];
    }
}
