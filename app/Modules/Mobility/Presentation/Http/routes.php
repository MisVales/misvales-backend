<?php

declare(strict_types=1);

use App\Modules\Mobility\Presentation\Http\Controllers\AdministrativeReassignmentController;
use App\Modules\Mobility\Presentation\Http\Controllers\ClientTransferController;
use App\Modules\Mobility\Presentation\Http\Controllers\CoordinatorReassignmentController;
use App\Modules\Mobility\Presentation\Http\Controllers\DistributorBranchChangeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('client-transfers/recipients', [ClientTransferController::class, 'recipients'])
        ->name('client-transfers.recipients');
    Route::get('client-transfers', [ClientTransferController::class, 'index'])->name('client-transfers.index');
    Route::post('client-transfers', [ClientTransferController::class, 'store'])
        ->middleware('throttle:mobility-sensitive')->name('client-transfers.store');
    Route::get('client-transfers/{transfer}', [ClientTransferController::class, 'show'])
        ->whereUuid('transfer')->name('client-transfers.show');
    Route::post('client-transfers/{transfer}/preaccept', [ClientTransferController::class, 'preaccept'])
        ->whereUuid('transfer')->middleware('throttle:mobility-sensitive')->name('client-transfers.preaccept');
    Route::post('client-transfers/{transfer}/preaccept-rejection', [ClientTransferController::class, 'rejectPreacceptance'])
        ->whereUuid('transfer')->middleware('throttle:mobility-sensitive')->name('client-transfers.preaccept-rejection');
    Route::post('client-transfers/{transfer}/origin-decision', [ClientTransferController::class, 'originDecision'])
        ->whereUuid('transfer')->middleware('throttle:mobility-sensitive')->name('client-transfers.origin-decision');
    Route::post('client-transfers/{transfer}/final-acceptance', [ClientTransferController::class, 'finalAcceptance'])
        ->whereUuid('transfer')->middleware('throttle:mobility-sensitive')->name('client-transfers.final-acceptance');
    Route::post('client-transfers/{transfer}/cancel', [ClientTransferController::class, 'cancel'])
        ->whereUuid('transfer')->middleware('throttle:mobility-sensitive')->name('client-transfers.cancel');

    Route::get('client-reassignments', [AdministrativeReassignmentController::class, 'index'])
        ->name('client-reassignments.index');
    Route::post('client-reassignments', [AdministrativeReassignmentController::class, 'store'])
        ->middleware('throttle:mobility-sensitive')->name('client-reassignments.store');
    Route::get('client-reassignments/{reassignment}', [AdministrativeReassignmentController::class, 'show'])
        ->whereUuid('reassignment')->name('client-reassignments.show');
    Route::post('client-reassignments/{reassignment}/validate', [AdministrativeReassignmentController::class, 'validateBatch'])
        ->whereUuid('reassignment')->middleware('throttle:mobility-sensitive')->name('client-reassignments.validate');
    Route::post('client-reassignments/{reassignment}/complete', [AdministrativeReassignmentController::class, 'complete'])
        ->whereUuid('reassignment')->middleware('throttle:mobility-sensitive')->name('client-reassignments.complete');

    Route::get('distributor-branch-changes', [DistributorBranchChangeController::class, 'index'])
        ->name('distributor-branch-changes.index');
    Route::post('distributor-branch-changes', [DistributorBranchChangeController::class, 'store'])
        ->middleware('throttle:mobility-sensitive')->name('distributor-branch-changes.store');
    Route::get('distributor-branch-changes/{change}', [DistributorBranchChangeController::class, 'show'])
        ->whereUuid('change')->name('distributor-branch-changes.show');
    Route::post('distributor-branch-changes/{change}/authorize', [DistributorBranchChangeController::class, 'authorizeChange'])
        ->whereUuid('change')->middleware('throttle:mobility-sensitive')->name('distributor-branch-changes.authorize');
    Route::post('distributor-branch-changes/{change}/client-destinations', [DistributorBranchChangeController::class, 'clientDestinations'])
        ->whereUuid('change')->middleware('throttle:mobility-sensitive')->name('distributor-branch-changes.client-destinations');
    Route::post('distributor-branch-changes/{change}/destination-coordinator', [DistributorBranchChangeController::class, 'destinationCoordinator'])
        ->whereUuid('change')->middleware('throttle:mobility-sensitive')->name('distributor-branch-changes.destination-coordinator');
    Route::post('distributor-branch-changes/{change}/complete', [DistributorBranchChangeController::class, 'complete'])
        ->whereUuid('change')->middleware('throttle:mobility-sensitive')->name('distributor-branch-changes.complete');
    Route::post('distributor-branch-changes/{change}/cancel', [DistributorBranchChangeController::class, 'cancel'])
        ->whereUuid('change')->middleware('throttle:mobility-sensitive')->name('distributor-branch-changes.cancel');

    Route::get('coordinator-reassignments', [CoordinatorReassignmentController::class, 'index'])
        ->name('coordinator-reassignments.index');
    Route::post('coordinator-reassignments', [CoordinatorReassignmentController::class, 'store'])
        ->middleware('throttle:mobility-sensitive')->name('coordinator-reassignments.store');
    Route::get('coordinator-reassignments/{batch}', [CoordinatorReassignmentController::class, 'show'])
        ->whereUuid('batch')->name('coordinator-reassignments.show');
    Route::post('coordinator-reassignments/{batch}/assignments', [CoordinatorReassignmentController::class, 'assignments'])
        ->whereUuid('batch')->middleware('throttle:mobility-sensitive')->name('coordinator-reassignments.assignments');
    Route::post('coordinator-reassignments/{batch}/validate', [CoordinatorReassignmentController::class, 'validateBatch'])
        ->whereUuid('batch')->middleware('throttle:mobility-sensitive')->name('coordinator-reassignments.validate');
    Route::post('coordinator-reassignments/{batch}/complete', [CoordinatorReassignmentController::class, 'complete'])
        ->whereUuid('batch')->middleware('throttle:mobility-sensitive')->name('coordinator-reassignments.complete');
});
