<?php

use App\Http\Controllers\HealthDiagnosticsController;
use Illuminate\Support\Facades\Route;

Route::get('/up', HealthDiagnosticsController::class)->name('health.diagnostics');
