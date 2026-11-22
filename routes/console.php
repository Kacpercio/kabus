<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Escrow
|--------------------------------------------------------------------------
|
| Checks order payments, applies automatic order status changes and sends
| vendor payouts and buyer refunds. Requires the scheduler to run, e.g. a cron
| entry: * * * * * cd /path/to/kabus && php artisan schedule:run
|
*/
Schedule::command('escrow:process')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();
