<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('students:send-birthday-notifications')->dailyAt('08:00');

Schedule::command('crm:scan-stale-leads')->hourly();

Schedule::command('tuition:send-debt-reminders')->dailyAt('08:30');

Schedule::command('bigtests:remind-upcoming')->dailyAt('07:45');
