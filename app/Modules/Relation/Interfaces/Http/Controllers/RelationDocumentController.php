<?php

declare(strict_types=1);

namespace App\Modules\Relation\Interfaces\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Relation\Infrastructure\Persistence\Models\RelationDocument;
use Illuminate\Http\JsonResponse;

class RelationDocumentController extends Controller
{
    /**
     * @param RelationDocument $document
     * @return JsonResponse
     */
    public function download(RelationDocument $document): JsonResponse
    {
        // $this->authorize('download', $document);

        // TODO: Implement actual download logic (PrivateDocumentStorage)
        // Ensure no PII in URL or caching
        
        return response()->json(['url' => 'https://private-storage.example.com/' . $document->storage_key]);
    }
}
