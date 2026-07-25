<?php

declare(strict_types=1);

use App\Modules\Client\Presentation\Http\Controllers\ClientController;
use Illuminate\Support\Facades\Route;

Route::middleware(['client.request-id', 'auth:sanctum', 'throttle:60,1'])
    ->controller(ClientController::class)
    ->prefix('clients')
    ->name('clients.')
    ->group(function (): void {
        Route::get('/', 'index')->name('index');
        Route::post('/', 'store')->middleware('throttle:10,1')->name('store');
        Route::get('/{client}', 'show')->whereUuid('client')->name('show');

        Route::get('/{client}/bank-accounts', 'bankAccounts')->whereUuid('client')->name('bank-accounts.index');
        Route::post('/{client}/bank-accounts', 'storeBankAccount')->whereUuid('client')->name('bank-accounts.store');

        Route::get('/{client}/portfolio-entries', 'portfolioEntries')->whereUuid('client')->name('portfolio-entries.index');
        Route::post('/{client}/portfolio-entries', 'storePortfolioEntry')->whereUuid('client')->name('portfolio-entries.store');
        Route::patch('/{client}/portfolio-entries/{entry}', 'updatePortfolioEntry')
            ->whereUuid(['client', 'entry'])
            ->name('portfolio-entries.update');
    });
