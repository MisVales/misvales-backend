<?php

declare(strict_types=1);

namespace App\Modules\Client\Persistence\Models;

use App\Modules\Client\Domain\Portfolio\PortfolioEntryType;
use App\Modules\Client\Domain\Portfolio\PortfolioStatus;
use App\Modules\Client\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Movimiento informativo sin efecto financiero fuera de M06.
 *
 * @property string $id
 * @property string $client_id
 * @property string $distributor_id
 * @property string $assignment_id
 * @property ?string $voucher_id
 * @property PortfolioEntryType $entry_type
 * @property ?string $amount
 * @property PortfolioStatus $informational_status
 * @property CarbonImmutable $occurred_on
 * @property ?string $note
 * @property string $request_hash
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 */
final class ClientPortfolioEntry extends Model
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

    /** @return HasMany<ClientPortfolioEntryRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(ClientPortfolioEntryRevision::class, 'entry_id');
    }

    protected function casts(): array
    {
        return [
            'entry_type' => PortfolioEntryType::class,
            'informational_status' => PortfolioStatus::class,
            'amount' => 'decimal:4',
            'occurred_on' => 'immutable_date',
            'note' => 'encrypted',
            'lock_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
