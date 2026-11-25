<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\MissedPunchFlag;
use App\Models\User;
use App\Notifications\MissedPunchAllowanceExceededNotification;
use App\Notifications\MissedPunchNotification;
use App\Notifications\MissedPunchWeeklyWarningNotification;
use App\Services\Audit\AuditService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationRecipients;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Acts on missed punches (MissedPunchTracker), each once:
 *   - every mid-day missed punch → the employee and HR;
 *   - the working week (Mon–Fri) reaching the early warning → the employee and HR;
 *   - the week going over the allowance → the employee is flagged for HR
 *     review (MissedPunchFlag, audited), HR is told to review, and the
 *     employee is told the allowance is used up and to contact HR.
 * Recipients are the employee and the HR covering their department — never
 * their manager or anyone else by default; Admin adds or excludes people on
 * Settings → Notifications & Email. Every notice is keyed, so a re-run
 * notifies nobody twice. A missed final check-out keeps its own alert
 * (MissingCheckoutService).
 */
class MissedPunchAlerts
{
    public function __construct(
        private MissedPunchTracker $tracker,
        private NotificationDispatcher $dispatcher,
        private NotificationRecipients $recipients,
    ) {}

    /** @return array{missed: int, notified: int, warned: int, flagged: int} */
    public function scan(Employee $employee, CarbonInterface $from, CarbonInterface $to): array
    {
        $result = ['missed' => 0, 'notified' => 0, 'warned' => 0, 'flagged' => 0];
        $employee->loadMissing(['user', 'department']);

        if ($employee->user === null) {
            return $result;
        }

        $people = $this->people($employee);
        $role = fn (User $u): string => $u->id === $employee->user_id ? 'employee' : 'hr_admin';
        $weeks = [];

        foreach ($this->tracker->between($employee, $from, $to) as $date => $missed) {
            $weekStart = MissedPunchTracker::weekStart(Carbon::parse($date))->toDateString();
            $weeks[$weekStart] ??= $this->tracker->weekCount($employee, Carbon::parse($date));

            foreach ($missed as $punch) {
                $result['missed']++;

                if ($punch['kind'] === MissedPunchTracker::KIND_CHECKOUT || $punch['resolved']) {
                    continue;
                }

                $notification = new MissedPunchNotification($employee, $punch, $weeks[$weekStart]);
                $result['notified'] += $this->dispatcher->sendToRecipients(
                    MissedPunchNotification::class, $people,
                    fn (User $u) => $notification->forRole($role($u)),
                    'missed_punch:'.$employee->id.':'.$punch['key'], $employee,
                );
            }
        }

        foreach ($weeks as $weekStart => $count) {
            $status = MissedPunchTracker::status($count);

            if ($status === MissedPunchTracker::STATUS_EXCEEDED) {
                $result['flagged'] += $this->exceeded($employee, $weekStart, $count, $people, $role);
            } elseif ($status === MissedPunchTracker::STATUS_WARNING) {
                $result['warned'] += $this->dispatcher->sendToRecipients(
                    MissedPunchWeeklyWarningNotification::class, $people,
                    fn (User $u) => (new MissedPunchWeeklyWarningNotification($employee, $count, $weekStart))->forRole($role($u)),
                    'missed_punch_week:'.$employee->id.':'.$weekStart, $employee,
                );
            }
        }

        return $result;
    }

    /**
     * Over the allowance: flag the week (once; the count follows the week),
     * audit it, and tell HR and the employee — each once.
     *
     * @param  Collection<int, User>  $people
     * @return int 1 when the flag was raised now
     */
    private function exceeded(Employee $employee, string $weekStart, int $count, Collection $people, callable $role): int
    {
        $allowance = MissedPunchTracker::allowance();
        $flag = MissedPunchFlag::firstOrCreate(
            ['employee_id' => $employee->id, 'week_start' => $weekStart],
            ['missed_count' => $count, 'allowance' => $allowance, 'flagged_at' => now()],
        );
        $raised = $flag->wasRecentlyCreated;

        if (! $raised && $count > $flag->missed_count) {
            $flag->update(['missed_count' => $count]);
        }

        if ($raised) {
            app(AuditService::class)->event('ATTENDANCE_MISSED_PUNCH_FLAGGED', AuditService::ATTENDANCE, $flag,
                new: ['week_start' => $weekStart, 'missed_count' => $count, 'allowance' => $allowance],
                subjectEmployeeId: $employee->id);
        }

        $this->dispatcher->sendToRecipients(
            MissedPunchAllowanceExceededNotification::class, $people,
            fn (User $u) => (new MissedPunchAllowanceExceededNotification($employee, $count, $allowance, $weekStart))->forRole($role($u)),
            'missed_punch_allowance:'.$employee->id.':'.$weekStart, $employee,
        );

        return $raised ? 1 : 0;
    }

    /**
     * The employee and the HR covering them.
     *
     * @return Collection<int, User>
     */
    private function people(Employee $employee): Collection
    {
        return collect([$employee->user])
            ->merge($this->recipients->hrCovering($employee)->reject(fn (User $u) => $u->id === $employee->user_id))
            ->filter()->unique('id')->values();
    }
}
