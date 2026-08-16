<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('organization:publish-outbox --limit=100')
    ->everyMinute();

Schedule::command('relations:generate --scheduled')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('relations:evaluate-late-fees --scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('risk:evaluate-distributors')->dailyAt('09:00')->timezone(config('relations.timezone'))->withoutOverlapping();
Schedule::command('notifications:project')->everyMinute()->withoutOverlapping();
Schedule::command('operations:heartbeat')->everyMinute()->withoutOverlapping();
