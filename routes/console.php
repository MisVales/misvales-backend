<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('access:outbox-dispatch')
    ->everyMinute()
    ->withoutOverlapping();
