<?php

declare(strict_types=1);

namespace App\Modules\Relation\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Modules\Relation\Domain\Enums\RelationDocumentStatus;

class RelationDocument extends Model
{
    use HasUuids;

    protected $table = 'relation_documents';

    protected $guarded = [];

    protected $casts = [
        'document_version' => 'integer',
        'status' => RelationDocumentStatus::class,
        'size_bytes' => 'integer',
        'generated_at' => 'datetime',
    ];

    public function relation()
    {
        return $this->belongsTo(Relation::class, 'relation_id');
    }
}
