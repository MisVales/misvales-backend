<?php

declare(strict_types=1);

namespace App\Modules\Relation\Application\Queries\ListRelationDocuments;

use App\Modules\Relation\Infrastructure\Persistence\Models\RelationDocument;
use Illuminate\Database\Eloquent\Collection;

class ListRelationDocumentsQuery
{
    public function handle(string $relationId): Collection
    {
        return RelationDocument::where('relation_id', $relationId)
            ->orderBy('document_version', 'desc')
            ->get();
    }
}
