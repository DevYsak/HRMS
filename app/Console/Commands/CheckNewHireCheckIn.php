<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\NewHireCheckInNotification;
use App\Services\Notifications\NotificationRecipients;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Spec §7 / §3.8 task 9 — when a new hire reaches 30 days, notify their
 * MANAGER (who holds the check-in) in-app; HR keeps its digest too.
 *
 * Every current employee counts — not only those still in "onboarding"
 * status, which most new hires have left by day 30. Each hire is announced
 * once (newhire_checkin_notified_at): a re-run sends nothing twice, and a
 * missed or failed run is caught up within a week. One failed delivery
 * never stops the others.
 */
class CheckNewHireCheckIn extends Command
{
    /** Not (or no longer) working here: an unconfirmed draft, or someone who left. */
    private const NOT_CURRENT = ['draft', 'inactive', 'archived', 'resigned', 'terminated', 'absconded'];

    /** How far back a missed run is caught up. */
    private const CATCH_UP_DAYS = 7;

    protected $signature = 'hrms:check-newhire-checkin';

    protected $description = 'Notify the manager (and HR) once when a new hire reaches their 30-day check-in.';

    public function handle(): int
    {
        $newHires = Employee::with(['user', 'manager'])
            ->whereDate('joining_date', '<=', now()->subDays(30)->toDateString())
            ->whereDate('joining_date', '>=', now()->subDays(30 + self::CATCH_UP_DAYS)->toDateString())
            ->whereNull('newhire_checkin_notified_at')
            ->whereNotIn('status', self::NOT_CURRENT)
            ->get();

        if ($newHires->isEmpty()) {
            $this->info('No 30-day new hire milestones to announce.');

            return self::SUCCESS;
        }

        $hrQueue = app(NotificationRecipients::class)->hrQueue();
        $announced = collect();

        // Managers first — the check-in is theirs (spec §7).
        $newHires->groupBy('manager_id')->each(function (Collection $hires) use ($hrQueue, $announced) {
            $manager = $hires->first()->manager;

            if ($manager && ! $hrQueue->contains('id', $manager->id)) {
                try {
                    $manager->notify(new NewHireCheckInNotification($hires->values()));
                } catch (\Throwable $e) {
                    report($e);

                    return; // left unmarked, retried on the next run
                }
            }

            $announced->push(...$hires->all());
        });

        $hrQueue->each(function (User $hr) use ($newHires) {
            try {
                $hr->notify(new NewHireCheckInNotification($newHires));
            } catch (\Throwable $e) {
                report($e);
            }
        });

        foreach ($announced as $hire) {
            Employee::withoutEvents(fn () => $hire->forceFill(['newhire_checkin_notified_at' => now()])->save());
        }

        $this->info("Notified managers and HR about {$announced->count()} new hire 30-day check-in(s).");

        return self::SUCCESS;
    }
}
