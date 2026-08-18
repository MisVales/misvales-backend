<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\ProductionConfigurationServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    ProductionConfigurationServiceProvider::class,
];
