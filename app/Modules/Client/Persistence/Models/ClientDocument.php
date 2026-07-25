<?php

declare(strict_types=1);

namespace App\Modules\Client\Persistence\Models;

use App\Modules\Client\Domain\Documents\ClientDocumentType;
use App\Modules\Client\Persistence\Models\Concerns\UsesUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Referencia versionada a un archivo privado administrado por M18.
 *
 * @property string $id
 * @property string $client_id
 * @property ClientDocumentType $document_type
 * @property string $media_id
 */
final class ClientDocument extends Model
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
            'document_type' => ClientDocumentType::class,
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'active_slot' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }
}
