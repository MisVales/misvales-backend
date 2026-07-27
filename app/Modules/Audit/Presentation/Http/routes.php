<?php

use App\Modules\Audit\Presentation\Http\Controllers\AuditController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/audit')->middleware('auth:sanctum')->group(function () {
    Route::get('/events', [AuditController::class, 'index']);
    Route::get('/events/{auditEvent}', [AuditController::class, 'show']);
    // Las rutas de resources / subjects se agregarían aquí
});
