<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/Access/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/Configuration/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/Credit/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/DistributorOnboarding/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/Client/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/Voucher/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/Payment/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/ExcessBalance/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/Points/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/RiskDelinquency/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/Mobility/Presentation/Http/routes.php'));

Route::prefix('v1')
    ->name('api.v1.')
    ->group(base_path('app/Modules/Reporting/Presentation/Http/routes.php'));
