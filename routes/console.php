<?php

use App\Jobs\QueueHeartbeat;
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

// Release approval claim-locks idle for 2+ hours (multi-HR routing) → hourly
Schedule::command('hrms:release-stale-claims')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Flag today's missing check-outs → 21:00 IST (IT shift ends 19:30 + 1 hr buffer; spec §7)
// and 23:05 IST (UK Sales shift ends 22:00 + 1 hr). Each run flags only days
// whose own shift deadline has passed; an already-flagged day is skipped.
// Coordinator attendance alerts — every 30 minutes through the working day.
// Deduplicated: an exception is reported once, and repeated only when the
// reminder interval (Settings → Coordinators) rolls over unresolved.
Schedule::command('hrms:coordinator-attendance-alerts')
    ->everyThirtyMinutes()
    ->between('09:30', '21:30')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('hrms:flag-missing-checkouts')
    ->dailyAt('21:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('hrms:flag-missing-checkouts')
    ->dailyAt('23:05')
    ->withoutOverlapping()
    ->runInBackground();

// Missing-checkout sweep: marks open days Missing Checkout once the employee's
// shift end + 1 hour has passed. It NEVER creates a check-out. Idempotent, and
// all day (not just the afternoon) so night shifts are caught at their own
// morning cutoff.
Schedule::command('hrms:auto-punch-out')
    ->everyTenMinutes()
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

// Rule 11: score yesterday for every active employee → 00:20 IST
Schedule::command('hrms:compute-attendance-scores')
    ->dailyAt('00:20')
    ->withoutOverlapping()
    ->runInBackground();

// Rule 10: DRAFT warning letters (or the next escalation) once monthly late
// marks reach the configured threshold → 14:30 IST daily. D10: drafts only —
// HR issues them from Warning Letters.
Schedule::command('hrms:issue-late-warnings')
    ->dailyAt('14:30')
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

// Nudge reviewers with pending multi-reviewer submissions → Monday 09:00
// (legacy hrms:send-review-reminders retired with the ReviewCycle cutover)
Schedule::command('hrms:remind-review-participants')
    ->weeklyOn(1, '09:00')
    ->withoutOverlapping()
    ->runInBackground();

// Remind HR to open the July increment cycle → June 1, 09:00
Schedule::command('hrms:open-increment-cycle-reminder')
    ->yearlyOn(6, 1, '09:00')
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

// The old calendar-year carry-forward command stays unscheduled.
//
// It ran unattended every 1 January at 02:00 — automatic, attributable to
// nobody, and on a date that is not a boundary of a leave year that runs
// 1 July to 30 June.
//
// Year-end now runs through leave:rollover (scheduled below for 1 July). It
// carries forward automatically only where the configuration states the
// decision — carry_forward_mode 'automatic', or a policy rule that enables
// carry forward. A type whose carry forward is an HR decision (hr_approval)
// is never carried by the schedule: its rows go to Needs HR Review, and HR
// states the amount on the Year Rollover / Carry Forward screens, recorded
// against the person who made it.

// Flag previous day absences without approved leave as PENDING Unauthorized
// Leave for HR to decide → 09:30 IST. D10: never approved unpaid leave by itself.
Schedule::command('hrms:flag-unauthorized-absences')
    ->dailyAt('09:30')
    ->withoutOverlapping()
    ->runInBackground();

// Sync Nexflow clock data and auto-create OT requests for excess hours → 09:00 IST (after shift closes)
Schedule::command('hrms:sync-nexflow-ot')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->runInBackground();

// Pull approved/rejected overtime from the Nexflow ot-details endpoint → every 10 min
Schedule::command('hrms:sync-nexflow-ot-details')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Pull biometric punch logs every 5 minutes during working hours (07:00–22:00 IST)
Schedule::command('hrms:sync-biometric')
    ->everyFiveMinutes()
    ->between('07:00', '22:00')
    ->withoutOverlapping()
    ->runInBackground();

// Pull computed daily attendance from the Python engine → every 10 min during working hours
Schedule::command('attendance:sync-engine')
    ->everyTenMinutes()
    ->between('07:00', '23:00')
    ->withoutOverlapping()
    ->runInBackground();

// Nightly catch-up: re-sync the last 14 days so short engine/network outages
// or late corrections self-heal without a manual backfill → 23:50 IST
Schedule::command('attendance:sync-engine --days=14 --resilient')
    ->dailyAt('23:50')
    ->withoutOverlapping()
    ->runInBackground();

// Push HRMS employee changes to biometric devices every 10 minutes (HRMS is master)
Schedule::command('biometric:push-employees')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Queue-worker liveness heartbeat — a running worker stamps queue:heartbeat_at
// each minute; the admin Queue Worker indicator reads it. → every minute
Schedule::job(new QueueHeartbeat)
    ->everyMinute()
    ->withoutOverlapping();

// Data retention: REPORT leave attachments past 30 days after approval → 01:00 daily.
// D10: report only — permanent deletion is run by a person with --delete.
Schedule::command('leave:purge-attachments')
    ->dailyAt('01:00')
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

// Conexus CSL — 1 day for each completed month, effective its last day. Runs
// at 00:15 on the 1st: before the 1 July rollover (01:30), so June's day is in
// the finishing year when it is carried forward. Idempotent; blocked
// employees are reported, never guessed. D7: scheduled only when explicitly
// enabled (leave_provisioning.csl_monthly_accrual_enabled, off by default).
Schedule::command('leave:conexus-csl-accrual --apply')
    ->monthlyOn(1, '00:15')
    ->when(fn () => (bool) config('leave_provisioning.csl_monthly_accrual_enabled'))
    ->withoutOverlapping()
    ->runInBackground();

// Phase 2B — provision any eligible employee missing this leave year's base
// entitlement (idempotent; mismatches and ambiguous balances are only reported)
Schedule::command('leave:ensure-balances --apply')
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->runInBackground();

// Phase 2C — year-end rollover on 1 July: SAFE rows only (carry forward,
// expire the rest, provision the new base); ambiguous rows go to HR review.
// Idempotent, so re-running it (or the Year Rollover screen) is harmless.
Schedule::command('leave:rollover --apply')
    ->yearlyOn(7, 1, '01:30')
    ->withoutOverlapping()
    ->runInBackground();

// Phase 2C — expire unused carry-forward/add-on lots past their date and send
// the 30- and 7-day expiry notices → daily 00:30
Schedule::command('leave:expire-credits')
    ->dailyAt('00:30')
    ->withoutOverlapping()
    ->runInBackground();

// Onboarding — Mark overdue tasks and notify owners → daily 09:00
Schedule::command('hrms:send-onboarding-reminders')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->runInBackground();

// Phase 2 — Performance Engine
// Remind employees of unacknowledged warnings older than 48 hours → daily 09:00
Schedule::command('hrms:check-warning-acknowledgements')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->runInBackground();

// Notify employees of goal deadlines within 7 days → Monday 08:00
Schedule::command('hrms:check-kpi-deadlines')
    ->weeklyOn(1, '08:00')
    ->withoutOverlapping()
    ->runInBackground();

// Remind employees nearing self-review deadline in active performance cycles → daily 08:30
Schedule::command('hrms:check-review-cycle-reminders')
    ->dailyAt('08:30')
    ->withoutOverlapping()
    ->runInBackground();

// Notify manager, HR, and employee of weekly PIP review due → Monday 09:00
Schedule::command('hrms:check-pip-weekly-review')
    ->weeklyOn(1, '09:00')
    ->withoutOverlapping()
    ->runInBackground();

// Spatie Backup
Schedule::command('backup:clean')->daily()->at('01:00');
Schedule::command('backup:run')->daily()->at('01:30');
