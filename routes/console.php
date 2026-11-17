<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Polling fallback for missed webhooks. Needs cron: * * * * * php artisan schedule:run
Schedule::command('pixelforge:reconcile')
    ->everyMinute()
    ->withoutOverlapping(10)   // a slow run never overlaps the next one (lock expires after 10 min)
    ->onOneServer();           // safe if the app ever runs on more than one server
