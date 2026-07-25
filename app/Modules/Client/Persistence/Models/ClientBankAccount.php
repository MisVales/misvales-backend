<?php

declare(strict_types=1);

namespace App\Modules\Client\Persistence\Models;

use App\Modules\Client\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versión protegida de un dato bancario para el depósito manual.
 *
 * @property string $id
 * @property string $client_id
 * @property string $account_ciphertext
 * @property string $account_hmac
 * @property string $account_last4
 * @property CarbonImmutable $effective_from
 * @property ?CarbonImmutable $effective_to
 * @property ?bool $active_slot
 */
final class ClientBankAccount extends Model
{
    use UsesUuidPrimaryKey;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'active_slot' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }
}
