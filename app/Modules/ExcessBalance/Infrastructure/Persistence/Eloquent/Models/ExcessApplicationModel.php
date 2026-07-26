<?php

declare(strict_types=1);

namespace App\Modules\ExcessBalance\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\Payment\Infrastructure\Persistence\Eloquent\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Aplicación financiera inmutable de saldo a favor a una relación posterior.
 *
 * @property string $id
 * @property string $relation_id
 * @property string $amount
 * @property string $available_before
 * @property string $available_after
 * @property CarbonImmutable|null $effective_at
 * @property CarbonImmutable|null $applied_at
 */
final class ExcessApplicationModel extends Model
{
    use UsesUuidPrimaryKey;

    public const UPDATED_AT = null;

    protected $table = 'excess_applications';

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'available_before' => 'decimal:4',
            'available_after' => 'decimal:4',
            'effective_at' => 'immutable_datetime',
            'applied_at' => 'immutable_datetime',
        ];
    }
}
