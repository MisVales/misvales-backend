<?php

declare(strict_types=1);

namespace App\Modules\Client\Persistence\Models;

use App\Modules\Client\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preferencia de seguimiento para una asociación distribuidora-cliente.
 *
 * @property string $id
 * @property string $client_id
 * @property string $distributor_id
 * @property string $assignment_id
 * @property bool $tracking_enabled
 * @property int $lock_version
 */
final class ClientPortfolioSetting extends Model
{
    use UsesUuidPrimaryKey;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<ClientDistributorAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ClientDistributorAssignment::class, 'assignment_id');
    }

    protected function casts(): array
    {
        return [
            'tracking_enabled' => 'boolean',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
