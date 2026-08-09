<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('organization:publish-outbox --limit=100')
    ->everyMinute();
