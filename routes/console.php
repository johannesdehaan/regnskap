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

// Oslo Børs has closed for the day by 16:45.
Schedule::command('holdings:update-prices')
    ->weekdays()
    ->at('16:45')
    ->timezone('Europe/Oslo')
    ->onOneServer();
