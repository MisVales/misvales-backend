<?php

use App\Modules\Media\Presentation\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/files')->middleware('auth:sanctum')->group(function () {
    Route::post('/upload-intents/{intent}/content', [MediaController::class, 'uploadContent']);
    // Las rutas de acceso/descarga irían aquí, protegidas y devolviendo streams o temporaryURLs
});
