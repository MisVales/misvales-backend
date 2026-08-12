<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('organization:publish-outbox --limit=100')
    ->everyMinute();

Schedule::command('relations:generate')
    ->monthlyOn((int) config('relations.cutoff_day'), config('relations.cutoff_time'))
    ->timezone(config('relations.timezone'))
    ->withoutOverlapping();

Schedule::command('relations:evaluate-late-fees')->dailyAt(config('surcharges.evaluation_time'))->timezone(config('relations.timezone'))->withoutOverlapping();
Schedule::command('risk:evaluate-distributors')->dailyAt('09:00')->timezone(config('relations.timezone'))->withoutOverlapping();
Schedule::command('notifications:project')->everyMinute()->withoutOverlapping();
