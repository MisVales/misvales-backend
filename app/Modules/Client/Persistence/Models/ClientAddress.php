<?php

declare(strict_types=1);

namespace App\Modules\Client\Persistence\Models;

use App\Modules\Client\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versión inmutable de un domicilio protegido.
 *
 * @property string $id
 * @property string $client_id
 * @property string $street_ciphertext
 * @property string $exterior_number_ciphertext
 * @property ?string $interior_number_ciphertext
 * @property string $neighborhood_ciphertext
 * @property string $postal_code_ciphertext
 * @property string $municipality_ciphertext
 * @property string $city_ciphertext
 * @property string $state_ciphertext
 * @property CarbonImmutable $effective_from
 * @property ?CarbonImmutable $effective_to
 */
final class ClientAddress extends Model
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
