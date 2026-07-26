<?php

use App\Modules\RiskDelinquency\Infrastructure\Jobs\RetryDeferredRiskEvaluationsJob;
use Illuminate\Support\Facades\Schedule;

Schedule::command('access:outbox-dispatch')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::job(new RetryDeferredRiskEvaluationsJob)
    ->dailyAt('08:30')
    ->timezone('America/Monterrey')
    ->withoutOverlapping();
