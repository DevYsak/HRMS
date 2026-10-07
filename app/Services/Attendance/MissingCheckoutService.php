<?php

namespace App\Services\Attendance;

use App\Models\Attendance;
use App\Models\User;
use App\Notifications\MissingCheckoutNotification;
use App\Services\Notifications\NotificationDispatcher;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Missing-checkout handling: STATE and notification only — never a punch.
 *
 * A day with a valid Face IN and no valid ID Card OUT becomes Missing
 * Checkout once shift end + 1 hour has passed (the employee's real shift,
 * so a night shift waits for its own morning cutoff). Nothing is invented:
 * check_out stays NULL, no OUT punch is written, no shift is credited and no
 * overtime is produced. The day is refreshed through
 * {@see AttendanceDayRebuilder}, so the row, summary and status all come from
 * the canonical timeline, and a regularised, HR-corrected or payroll-settled
 * day is left exactly as it is.
 *
 * Idempotent: a repeat run changes nothing and notifies nobody twice — the
 * notification is keyed on the attendance row (one employee, one work date).
 */
class MissingCheckoutService
{
    public function __construct(
        private readonly AttendanceStatusResolver $status,
        private readonly AttendanceDayRebuilder $rebuilder,
    ) {}

    /**
     * Sweep one work date.
     *
     * @return array{checked: int, flagged: int, notified: int, protected: int}
     */
    public function sweep(CarbonInterface $date, ?CarbonInterface $now = null): array
    {
        $now = $now ? Carbon::parse($now) : Carbon::now();
        $result = ['checked' => 0, 'flagged' => 0, 'notified' => 0, 'protected' => 0];

        $open = Attendance::with(['employee.user', 'employee.manager', 'employee.shift'])
            ->whereDate('date', $date->toDateString())
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->get();

        foreach ($open as $attendance) {
            $employee = $attendance->employee;
            if (! $employee) {
                continue;
            }

            $result['checked']++;

            // Working, completed or not yet past the cutoff: nothing to do.
            if ($this->status->forAttendance($attendance, now: $now)['state'] !== AttendanceStatusResolver::MISSING_CHECKOUT) {
                continue;
            }

            try {
                $row = $this->rebuilder->examine($employee, $attendance->date, now: $now);

                if ($row['skip'] !== null && $row['skip'] !== 'no valid Face IN') {
                    $result['protected']++;   // regularised / HR-corrected / settled payroll / not credible: untouched

                    continue;
                }

                $wasFlagged = (bool) $attendance->missing_checkout;

                if ($row['skip'] === null) {
                    $this->rebuilder->apply($employee, $row);   // check_out stays NULL, hours stop at the cutoff
                } elseif (! $wasFlagged) {
                    // A web-punch day has no device punches to rebuild from: set the flag only.
                    $attendance->update(['missing_checkout' => true]);
                }

                $attendance->refresh();
                if (! $attendance->missing_checkout) {
                    $attendance->update(['missing_checkout' => true]);
                }

                $result['flagged'] += $wasFlagged ? 0 : 1;
                $result['notified'] += $this->notify($attendance);
            } catch (\Throwable $e) {
                report($e);   // one bad record never stops the rest of the run
            }
        }

        return $result;
    }

    /**
     * The employee and their manager (plus whoever the Notifications & Email
     * page adds, minus excluded roles), each once for this day.
     *
     * @return int how many were sent now
     */
    private function notify(Attendance $attendance): int
    {
        $employee = $attendance->employee;
        $notification = new MissingCheckoutNotification($attendance);

        return app(NotificationDispatcher::class)->sendToRecipients(
            MissingCheckoutNotification::class,
            collect([$employee->user, $employee->manager_id ? $employee->manager : null])->filter(),
            fn (User $u) => $notification->forRole($u->id === $employee->user_id ? 'employee' : 'manager'),
            'missing_checkout:'.$attendance->id,
            $employee,
        );
    }
}
