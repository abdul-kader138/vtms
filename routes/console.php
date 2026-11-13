<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tax:mark-overdue')->dailyAt('00:15');
Schedule::command('tax:send-reminders')->dailyAt('08:00');
Schedule::command('tax:import-inbox')->everyFifteenMinutes()->withoutOverlapping();

// Backups: nightly database + uploaded files, old ones thinned out, and a
// morning check that mails if the newest backup is missing or too old.
Schedule::command('backup:clean')->dailyAt('01:00');
Schedule::command('backup:run')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('backup:monitor')->dailyAt('07:00');
