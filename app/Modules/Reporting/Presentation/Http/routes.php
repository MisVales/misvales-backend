<?php

declare(strict_types=1);

use App\Modules\Reporting\Presentation\Http\Controllers\ReportController;
use App\Modules\Reporting\Presentation\Http\Middleware\AuditReportingFailure;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', AuditReportingFailure::class])->group(function (): void {
    Route::get('reports', [ReportController::class, 'index'])
        ->middleware('throttle:reporting-queries')->name('reports.index');
    Route::get('reports/{code}/definition', [ReportController::class, 'definition'])
        ->where('code', '[A-Z_]+')->middleware('throttle:reporting-queries')->name('reports.definition');
    Route::get('reports/{code}', [ReportController::class, 'execute'])
        ->where('code', '[A-Z_]+')->middleware('throttle:reporting-queries')->name('reports.execute');
    Route::post('reports/{code}/runs', [ReportController::class, 'createRun'])
        ->where('code', '[A-Z_]+')->middleware('throttle:reporting-runs')->name('reports.runs.store');
    Route::get('report-runs', [ReportController::class, 'runs'])
        ->middleware('throttle:reporting-queries')->name('report-runs.index');
    Route::get('report-runs/{run}', [ReportController::class, 'run'])
        ->whereUuid('run')->middleware('throttle:reporting-queries')->name('report-runs.show');
    Route::get('report-runs/{run}/results', [ReportController::class, 'results'])
        ->whereUuid('run')->middleware('throttle:reporting-queries')->name('report-runs.results');
});
