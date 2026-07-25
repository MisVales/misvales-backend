<?php

declare(strict_types=1);

namespace App\Modules\Client\Persistence\Models;

use App\Modules\Client\Domain\Portfolio\PortfolioStatus;
use App\Modules\Client\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Historial inmutable de campos informativos corregibles. */
final class ClientPortfolioEntryRevision extends Model
{
    use UsesUuidPrimaryKey;

    public const CREATED_AT = 'changed_at';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return BelongsTo<ClientPortfolioEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(ClientPortfolioEntry::class, 'entry_id');
    }

    protected function casts(): array
    {
        return [
            'previous_note' => 'encrypted',
            'new_note' => 'encrypted',
            'previous_status' => PortfolioStatus::class,
            'new_status' => PortfolioStatus::class,
            'previous_version' => 'integer',
            'changed_at' => 'immutable_datetime',
        ];
    }
}
