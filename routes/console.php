<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sparebank1:sync')
    ->everySixHours()
    ->withoutOverlapping()
    ->onOneServer();

// The times are in each user's price schedule in the database. This check
// starts the update when one of them has passed, and marks the time as handled
// there, so it needs no lock here to run once.
Schedule::command('holdings:update-prices --due')->everyMinute();
