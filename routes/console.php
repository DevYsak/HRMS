<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// --------------------------------------------------
// HRMS Scheduled Jobs (all times IST)
// --------------------------------------------------

// Escalate leave requests not reviewed within 24 hours → hourly
Schedule::command('hrms:escalate-leaves')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Flag today's missing check-outs → 21:00 IST (IT shift ends 19:30 + 1 hr buffer; spec §7)
Schedule::command('hrms:flag-missing-checkouts')
    ->dailyAt('21:00')
    ->withoutOverlapping()
    ->runInBackground();

// Confirm late flags for IT shift (10:30 start + 5 min grace) → 10:45 IST
Schedule::command('hrms:check-late-arrivals')
    ->dailyAt('10:45')
    ->withoutOverlapping()
    ->runInBackground();

// Confirm late flags for UK Sales shift (13:00 start + 5 min grace) → 13:15 IST
Schedule::command('hrms:check-late-arrivals')
    ->dailyAt('13:15')
    ->withoutOverlapping()
    ->runInBackground();

// Flag excess breaks (>60 min) for today → 20:00 IST (after IT shift ends at 19:30)
Schedule::command('hrms:check-excess-breaks')
    ->dailyAt('20:00')
    ->withoutOverlapping()
    ->runInBackground();

// Escalate OT requests pending > 24 hours to HR → hourly
Schedule::command('hrms:escalate-ot')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Notify HR of documents expiring within 30 days → 08:00 daily
Schedule::command('hrms:check-document-expiry')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground();

// Notify manager + HR Admin 10 days before probation end → 08:00 daily
Schedule::command('hrms:check-probation-due')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground();

// Notify manager when employee hits 30-day milestone → 08:00 daily
Schedule::command('hrms:check-newhire-checkin')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground();

// Notify employees + managers if QBR review due within 7 days → Monday 09:00
Schedule::command('hrms:send-review-reminders')
    ->weeklyOn(1, '09:00')
    ->withoutOverlapping()
    ->runInBackground();

// Delete notifications older than 90 days → Sunday midnight
Schedule::command('hrms:prune-notifications')
    ->weeklyOn(0, '00:00')
    ->withoutOverlapping()
    ->runInBackground();

// Generate previous month attendance summary → 1st of each month at 01:00
Schedule::command('hrms:generate-attendance-summary')
    ->monthlyOn(1, '01:00')
    ->withoutOverlapping()
    ->runInBackground();

// Roll over remaining leave balances to new year → 1 Jan 02:00 (after attendance summary)
Schedule::command('hrms:carry-forward-leaves')
    ->yearlyOn(1, 1, '02:00')
    ->withoutOverlapping()
    ->runInBackground();

// Flag previous day absences without approved leave as Unauthorized Leave → 09:30 IST (after grace window)
Schedule::command('hrms:flag-unauthorized-absences')
    ->dailyAt('09:30')
    ->withoutOverlapping()
    ->runInBackground();

// Sync Nexflow clock data and auto-create OT requests for excess hours → 09:00 IST (after shift closes)
Schedule::command('hrms:sync-nexflow-ot')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->runInBackground();

// Pull biometric punch logs every 5 minutes during working hours (07:00–22:00 IST)
Schedule::command('hrms:sync-biometric')
    ->everyFiveMinutes()
    ->between('07:00', '22:00')
    ->withoutOverlapping()
    ->runInBackground();

// Push HRMS employee changes to biometric devices every 10 minutes (HRMS is master)
Schedule::command('biometric:push-employees')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Phase 1A — Employee Lifecycle Engine
// Auto-advance date-driven transitions (notice period end → resigned) → 00:30 daily
Schedule::command('hrms:process-lifecycle-transitions')
    ->dailyAt('00:30')
    ->withoutOverlapping()
    ->runInBackground();

// Notify HR of overdue or soon-expiring probation periods → 08:00 daily
Schedule::command('hrms:check-probation-expiry')
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->runInBackground();

// Phase 1B — Leave Management Engine
// Credit monthly leave accruals on the 1st of each month at 06:00
Schedule::command('hrms:monthly-leave-accrual')
    ->monthlyOn(1, '06:00')
    ->withoutOverlapping()
    ->runInBackground();

// Spatie Backup
Schedule::command('backup:clean')->daily()->at('01:00');
Schedule::command('backup:run')->daily()->at('01:30');
