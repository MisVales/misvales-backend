<?php

declare(strict_types=1);

use App\Modules\DistributorOnboarding\Presentation\Http\Controllers\DistributorApplicationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['request.id', 'onboarding.failures', 'auth:sanctum'])
    ->controller(DistributorApplicationController::class)
    ->prefix('distributor-applications')
    ->name('distributor-applications.')
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->name('store');
        Route::get('/{id}', 'show')->whereUuid('id')->name('show');
        Route::patch('/{id}', 'update')->whereUuid('id')->name('update');
        Route::post('/{id}/submit', 'submit')->whereUuid('id')->name('submit');
        Route::get('/{id}/history', 'history')->whereUuid('id')->name('history');
        Route::post('/{id}/request-document-correction', 'requestDocumentCorrection')->whereUuid('id')->name('request-document-correction');
        Route::post('/{id}/assign-verifier', 'assignVerifier')->whereUuid('id')->name('assign-verifier');
        Route::post('/{id}/visits', 'startVisit')->whereUuid('id')->name('visits.store');
        Route::get('/{id}/visits/{visitId}', 'showVisit')->whereUuid(['id', 'visitId'])->name('visits.show');
        Route::post('/{id}/visits/{visitId}/differences', 'recordDifference')->whereUuid(['id', 'visitId'])->name('visits.differences.store');
        Route::post('/{id}/visits/{visitId}/complete', 'completeVisit')->whereUuid(['id', 'visitId'])->name('visits.complete');
        Route::post('/{id}/corrections', 'recordCorrection')->whereUuid('id')->name('corrections.store');
        Route::post('/{id}/corrections/complete', 'completeCorrections')->whereUuid('id')->name('corrections.complete');
        Route::post('/{id}/coordinator-decision', 'coordinatorDecision')->whereUuid('id')->name('coordinator-decision');
        Route::post('/{id}/manager-decision', 'managerDecision')->whereUuid('id')->name('manager-decision');
        Route::get('/{id}/activation', 'activation')->whereUuid('id')->name('activation');
    });
