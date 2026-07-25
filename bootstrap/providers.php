<?php

use App\Modules\Configuration\Infrastructure\Providers\ConfigurationServiceProvider;
use App\Modules\Credit\Infrastructure\Providers\CreditServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    ConfigurationServiceProvider::class,
    CreditServiceProvider::class,
    HorizonServiceProvider::class,
];
