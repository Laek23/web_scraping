<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('maxun:run-monitors')->everyMinute()->withoutOverlapping();
Schedule::command('deals:queue-price-updates')->everyFiveMinutes()->withoutOverlapping();
