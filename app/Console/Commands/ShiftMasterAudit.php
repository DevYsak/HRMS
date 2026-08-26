<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Read-only audit of the shift master.
 *
 * Duplicates are found by SCHEDULE, not by name. That is the whole point: the
 * pair that prompted this audit is "IT Shift" and "10.30 AM to 7.30 PM", two
 * names for one 10:30–19:30 window, and no name comparison would ever have
 * matched them.
 *
 * This command writes nothing. It reports what a merge would have to deal with
 * so the decision can be made before anything moves.
 */
class ShiftMasterAudit extends Command
{
    protected $signature = 'shifts:audit {--json : Emit the findings as JSON}';

    protected $description = 'Audit the shift master for duplicate schedules and report their usage (read-only)';

    public function handle(): int
    {
        $shifts = ShiftSetting::withTrashed()->orderBy('id')->get();

        if ($shifts->isEmpty()) {
            $this->warn('No shifts defined.');

            return self::SUCCESS;
        }

        $rows = $shifts->map(fn (ShiftSetting $s) => $this->describe($s));

        if ($this->option('json')) {
            $this->line(json_encode([
                'shifts' => $rows->values()->all(),
                'duplicate_groups' => $this->duplicateGroups($shifts)->values()->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Shift master — '.$shifts->count().' shift(s)');
        $this->newLine();

        $this->table(
            ['ID', 'Name', 'Start', 'End', 'Grace', 'Status', 'Employees', 'Scoped approvers'],
            $rows->map(fn (array $r) => [
                $r['id'],
                $r['name'],
                $r['start_time'],
                $r['end_time'],
                $r['grace_minutes'].'m',
                $r['status'],
                $r['employee_count'],
                $r['scoped_user_count'],
            ])->all(),
        );

        $groups = $this->duplicateGroups($shifts);

        if ($groups->isEmpty()) {
            $this->newLine();
            $this->info('No duplicate schedules found.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error($groups->count().' duplicate schedule group(s) found:');

        foreach ($groups as $group) {
            $this->newLine();
            $this->line("  <fg=yellow>{$group['schedule']}</> — {$group['count']} shifts share this window");

            foreach ($group['shifts'] as $s) {
                $marker = $s['id'] === $group['suggested_canonical_id'] ? '<fg=green>keep</>' : '<fg=red>duplicate</>';
                $this->line("    [{$marker}] #{$s['id']} \"{$s['name']}\" — {$s['employee_count']} employee(s), grace {$s['grace_minutes']}m, {$s['status']}");
            }

            if ($group['grace_differs']) {
                // Worth stopping for: attendance does not store the shift it was
                // scored against, so moving somebody between shifts with
                // different grace silently rescores their history.
                $this->line('    <fg=red>WARNING: grace periods differ across this group.</>');
                $this->line('    <fg=red>Reassigning would change how past lateness is calculated.</>');
            }

            if ($group['employees_to_reassign'] > 0) {
                $this->line("    {$group['employees_to_reassign']} employee(s) sit on a duplicate and would need reassignment.");
            } else {
                $this->line('    <fg=green>Nobody is assigned to the duplicate — it can be archived directly.</>');
            }
        }

        $this->newLine();
        $this->line('This command changed nothing. To act on it, review the groups above and run:');
        $this->line('  <fg=cyan>php artisan shifts:merge-duplicates --canonical=<id> --duplicate=<id></> (dry run by default)');

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function describe(ShiftSetting $shift): array
    {
        return [
            'id' => $shift->id,
            'name' => $shift->name,
            'start_time' => $this->time($shift->start_time),
            'end_time' => $this->time($shift->end_time),
            'grace_minutes' => (int) ($shift->grace_minutes ?? 0),
            'status' => $shift->trashed() ? 'archived' : 'active',
            'employee_count' => Employee::withTrashed()->where('shift_id', $shift->id)->count(),
            'scoped_user_count' => $this->scopedUsers($shift->id)->count(),
        ];
    }

    /**
     * Shifts sharing a start and end time, grouped.
     *
     * @param  Collection<int, ShiftSetting>  $shifts
     * @return Collection<int, array<string, mixed>>
     */
    private function duplicateGroups(Collection $shifts): Collection
    {
        return $shifts
            ->groupBy(fn (ShiftSetting $s) => $this->time($s->start_time).'-'.$this->time($s->end_time))
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->map(function (Collection $group, string $schedule) {
                $described = $group->map(fn (ShiftSetting $s) => $this->describe($s));
                $canonical = $this->suggestCanonical($described);

                return [
                    'schedule' => $schedule,
                    'count' => $group->count(),
                    'shifts' => $described->values()->all(),
                    'total_employees' => $described->sum('employee_count'),
                    // Only the non-canonical rows move; the group total would
                    // overstate the work by counting people already in the
                    // right place.
                    'employees_to_reassign' => $described->sum('employee_count') - (int) ($described->firstWhere('id', $canonical)['employee_count'] ?? 0),
                    'grace_differs' => $described->pluck('grace_minutes')->unique()->count() > 1,
                    // A suggestion only. The name that is not a restatement of
                    // the clock is almost always the one HR meant to keep, but
                    // the choice stays with whoever runs the merge.
                    'suggested_canonical_id' => $canonical,
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $described
     */
    private function suggestCanonical(Collection $described): int
    {
        $active = $described->where('status', 'active');
        $pool = $active->isNotEmpty() ? $active : $described;

        // A shift named after its own hours is what the importer invents when a
        // spreadsheet cell holds a time range; a real name was chosen by a human.
        $named = $pool->reject(fn (array $s) => $this->looksLikeATimeRange($s['name']));

        return (int) ($named->isNotEmpty() ? $named->first()['id'] : $pool->first()['id']);
    }

    private function looksLikeATimeRange(string $name): bool
    {
        return (bool) preg_match('/^\s*\d{1,2}[.:]\d{2}\s*(am|pm)?\s*(to|-|–|—)\s*\d{1,2}[.:]\d{2}\s*(am|pm)?\s*$/i', $name);
    }

    /** @return Collection<int, User> */
    private function scopedUsers(int $shiftId): Collection
    {
        return User::whereNotNull('scope_shifts')->get()
            ->filter(fn (User $u) => in_array($shiftId, (array) $u->scope_shifts, false));
    }

    private function time(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        return $value instanceof \DateTimeInterface
            ? $value->format('H:i')
            : substr((string) $value, 0, 5);
    }
}
