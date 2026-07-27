<?php

declare(strict_types=1);

namespace App\Modules\Relation\Interfaces\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Relation\Infrastructure\Persistence\Models\RelationDocument;
use App\Modules\Relation\Application\Queries\ListRelationDocuments\ListRelationDocumentsQuery;
use Illuminate\Http\JsonResponse;

class RelationDocumentController extends Controller
{
    public function index(string $relationId, ListRelationDocumentsQuery $query): JsonResponse
    {
        $documents = $query->handle($relationId);
        return response()->json($documents);
    }

    public function download(RelationDocument $document): JsonResponse
    {
        return response()->json(['url' => 'https://private-storage.example.com/' . $document->storage_key]);
    }
}
