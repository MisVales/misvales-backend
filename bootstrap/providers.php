<?php

use App\Modules\Configuration\Infrastructure\Providers\ConfigurationServiceProvider;
use App\Modules\Credit\Infrastructure\Providers\CreditServiceProvider;
use App\Modules\ExcessBalance\Infrastructure\Providers\ExcessBalanceServiceProvider;
use App\Modules\Mobility\Infrastructure\Providers\MobilityServiceProvider;
use App\Modules\Payment\Infrastructure\Providers\PaymentServiceProvider;
use App\Modules\Points\Infrastructure\Providers\PointsServiceProvider;
use App\Modules\Reporting\Infrastructure\Providers\ReportingServiceProvider;
use App\Modules\RiskDelinquency\Infrastructure\Providers\RiskDelinquencyServiceProvider;
use App\Modules\Voucher\Infrastructure\Providers\VoucherServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\ClientServiceProvider;
use App\Providers\DistributorOnboardingServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    ConfigurationServiceProvider::class,
    CreditServiceProvider::class,
    ExcessBalanceServiceProvider::class,
    DistributorOnboardingServiceProvider::class,
    ClientServiceProvider::class,
    PaymentServiceProvider::class,
    PointsServiceProvider::class,
    RiskDelinquencyServiceProvider::class,
    MobilityServiceProvider::class,
    ReportingServiceProvider::class,
    VoucherServiceProvider::class,
    HorizonServiceProvider::class,
];
