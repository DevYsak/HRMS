<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// --------------------------------------------------
// HRMS Scheduled Jobs
// --------------------------------------------------

// Escalate leave requests not reviewed within 24 hours → runs every hour
Schedule::command('hrms:escalate-leaves')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Flag attendance records from yesterday with no check-out → runs at 08:00 daily
Schedule::command('hrms:flag-missing-checkouts')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground();

// Flag yesterday's late arrivals beyond grace period → runs at 09:30 daily
Schedule::command('hrms:check-late-arrivals')
    ->dailyAt('09:30')
    ->withoutOverlapping()
    ->runInBackground();

// Flag attendance records with break time > 60 mins → runs at 20:00 daily
Schedule::command('hrms:check-excess-breaks')
    ->dailyAt('20:00')
    ->withoutOverlapping()
    ->runInBackground();

// Escalate OT requests pending > 24 hours to HR → runs every hour
Schedule::command('hrms:escalate-ot')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Notify HR of documents expiring within 30 days → runs at 08:00 daily
Schedule::command('hrms:check-document-expiry')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground();

// Alert HR of employees whose probation review is due within 10 days → runs at 08:00 daily
Schedule::command('hrms:check-probation-due')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground();

// Send HR check-in reminder for employees at their 30-day milestone → runs at 08:00 daily
Schedule::command('hrms:check-newhire-checkin')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground();

// Send weekly performance review reminders to employees with pending reviews → runs Monday at 09:00
Schedule::command('hrms:send-review-reminders')
    ->weeklyOn(1, '09:00')
    ->withoutOverlapping()
    ->runInBackground();

// Delete read notifications older than 90 days → runs Sunday at midnight
Schedule::command('hrms:prune-notifications')
    ->weeklyOn(0, '00:00')
    ->withoutOverlapping()
    ->runInBackground();

// Generate monthly attendance summary for all active employees → runs on 1st of each month at 01:00
Schedule::command('hrms:generate-attendance-summary')
    ->monthlyOn(1, '01:00')
    ->withoutOverlapping()
    ->runInBackground();

// Spatie Backup commands
Schedule::command('backup:clean')->daily()->at('01:00');
Schedule::command('backup:run')->daily()->at('01:30');
