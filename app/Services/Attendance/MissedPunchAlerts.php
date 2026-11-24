<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\MissedPunchNotification;
use App\Notifications\MissedPunchWeeklyWarningNotification;
use App\Services\Notifications\NotificationDispatcher;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Turns missed punches (MissedPunchTracker) into notifications: one per
 * mid-day missed punch, and one warning per week that reaches
 * MissedPunchTracker::WEEKLY_WARNING_AT — each to the employee and their
 * manager (plus whoever HR adds on the Notifications & Email page).
 * Idempotent: every notification is keyed, so a re-run notifies nobody twice.
 * A missed final check-out keeps its own alert (MissingCheckoutService).
 */
class MissedPunchAlerts
{
    public function __construct(
        private MissedPunchTracker $tracker,
        private NotificationDispatcher $dispatcher,
    ) {}

    /** @return array{missed: int, notified: int, warned: int} */
    public function scan(Employee $employee, CarbonInterface $from, CarbonInterface $to): array
    {
        $result = ['missed' => 0, 'notified' => 0, 'warned' => 0];
        $employee->loadMissing(['user', 'manager', 'department']);

        if ($employee->user === null) {
            return $result;
        }

        $recipients = collect([$employee->user, $employee->manager_id ? $employee->manager : null])->filter();
        $role = fn (User $u): string => $u->id === $employee->user_id ? 'employee' : 'manager';
        $weeks = [];

        foreach ($this->tracker->between($employee, $from, $to) as $date => $missed) {
            $weekStart = Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString();
            $weeks[$weekStart] ??= $this->tracker->weekCount($employee, Carbon::parse($date));

            foreach ($missed as $punch) {
                $result['missed']++;

                if ($punch['kind'] === MissedPunchTracker::KIND_CHECKOUT || $punch['resolved']) {
                    continue;
                }

                $notification = new MissedPunchNotification($employee, $punch, $weeks[$weekStart]);
                $result['notified'] += $this->dispatcher->sendToRecipients(
                    MissedPunchNotification::class, $recipients,
                    fn (User $u) => $notification->forRole($role($u)),
                    'missed_punch:'.$employee->id.':'.$punch['key'], $employee,
                );
            }
        }

        foreach ($weeks as $weekStart => $count) {
            if ($count < MissedPunchTracker::WEEKLY_WARNING_AT) {
                continue;
            }

            $warning = new MissedPunchWeeklyWarningNotification($employee, $count, $weekStart);
            $result['warned'] += $this->dispatcher->sendToRecipients(
                MissedPunchWeeklyWarningNotification::class, $recipients,
                fn (User $u) => $warning->forRole($role($u)),
                'missed_punch_week:'.$employee->id.':'.$weekStart, $employee,
            );
        }

        return $result;
    }
}
