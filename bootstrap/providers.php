<?php

use App\Modules\Credit\Infrastructure\Providers\CreditServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\DistributorOnboardingServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    CreditServiceProvider::class,
    DistributorOnboardingServiceProvider::class,
    HorizonServiceProvider::class,
];
