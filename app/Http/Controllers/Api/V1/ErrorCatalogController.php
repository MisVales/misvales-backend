<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ErrorCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErrorCatalogController extends Controller
{
    public function __invoke(Request $request, ErrorCatalogService $catalog): JsonResponse
    {
        abort_unless($request->user()?->hasRole('admin'), 403);

        $items = $catalog->all();

        return response()->json([
            'data' => $items,
            'meta' => ['total' => count($items)],
        ]);
    }
}
