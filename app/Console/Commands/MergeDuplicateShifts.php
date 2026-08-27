<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fold a duplicate shift into the canonical one.
 *
 * Dry run unless --apply is passed. Reassigning a shift changes how attendance
 * is scored — attendance rows do not store the shift they were measured
 * against, so the engine resolves the employee's current shift every time it
 * scores a past day. That makes this safe only when the two schedules genuinely
 * match, and the command refuses when they do not.
 *
 * The duplicate is archived, never deleted. Soft-deleting leaves employees.
 * shift_id intact, so any row still pointing at it keeps resolving; a hard
 * delete would fire the SET NULL foreign key and quietly strip the shift from
 * anyone the reassignment missed.
 */
class MergeDuplicateShifts extends Command
{
    protected $signature = 'shifts:merge-duplicates
        {--canonical= : ID of the shift to keep}
        {--duplicate= : ID of the shift to fold in and archive}
        {--apply : Actually make the change. Without this the command only reports}
        {--force-mismatch : Proceed even if the two schedules differ (dangerous)}';

    protected $description = 'Reassign employees off a duplicate shift onto the canonical one and archive it';

    public function handle(): int
    {
        $canonical = ShiftSetting::find($this->option('canonical'));
        $duplicate = ShiftSetting::withTrashed()->find($this->option('duplicate'));

        if (! $canonical || ! $duplicate) {
            $this->error('Both --canonical and --duplicate must name an existing shift. Run shifts:audit first.');

            return self::FAILURE;
        }

        if ($canonical->id === $duplicate->id) {
            $this->error('The canonical and duplicate shift are the same record.');

            return self::FAILURE;
        }

        $mismatch = $this->scheduleMismatch($canonical, $duplicate);

        if ($mismatch && ! $this->option('force-mismatch')) {
            $this->error('These two shifts are not the same schedule:');
            $this->line("  #{$canonical->id} \"{$canonical->name}\" — {$this->window($canonical)}, grace {$canonical->grace_minutes}m");
            $this->line("  #{$duplicate->id} \"{$duplicate->name}\" — {$this->window($duplicate)}, grace {$duplicate->grace_minutes}m");
            $this->newLine();
            $this->line('Merging them would change how affected employees\' past attendance is scored.');
            $this->line('Pass --force-mismatch only if that is genuinely intended.');

            return self::FAILURE;
        }

        $employees = Employee::withTrashed()->where('shift_id', $duplicate->id)->get();
        $scopedUsers = User::whereNotNull('scope_shifts')->get()
            ->filter(fn (User $u) => in_array($duplicate->id, (array) $u->scope_shifts, false));

        $this->info(($this->option('apply') ? 'Merging' : 'DRY RUN — would merge')." #{$duplicate->id} \"{$duplicate->name}\" into #{$canonical->id} \"{$canonical->name}\"");
        $this->newLine();
        $this->line("  Employees to reassign: {$employees->count()}");

        foreach ($employees as $employee) {
            $this->line("    - {$employee->employee_id} {$employee->user?->name}");
        }

        if ($scopedUsers->isNotEmpty()) {
            // Archiving a shift somebody is scoped to would silently narrow what
            // they can approve, which is a permissions change wearing a data
            // cleanup's clothes.
            $this->newLine();
            $this->warn("  {$scopedUsers->count()} approver(s) are scoped to this shift and will have it repointed:");
            foreach ($scopedUsers as $user) {
                $this->line("    - {$user->name}");
            }
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->line('Nothing was changed. Re-run with <fg=cyan>--apply</> to commit.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($canonical, $duplicate, $employees, $scopedUsers) {
            foreach ($employees as $employee) {
                $employee->timestamps = false;
                $employee->forceFill(['shift_id' => $canonical->id])->save();
                $employee->timestamps = true;

                AuditLog::record($employee, 'shift.reassigned', ['shift_id' => $duplicate->id], [
                    'shift_id' => $canonical->id,
                    'from_shift' => $duplicate->name,
                    'to_shift' => $canonical->name,
                    'reason' => 'Duplicate shift merge',
                ], 'Duplicate shift merge', $employee->id);
            }

            foreach ($scopedUsers as $user) {
                $scope = array_values(array_unique(array_map(
                    fn ($id) => (int) $id === $duplicate->id ? $canonical->id : (int) $id,
                    (array) $user->scope_shifts,
                )));
                $user->forceFill(['scope_shifts' => $scope])->save();
            }

            AuditLog::record($duplicate, 'shift.archived', $duplicate->toArray(), [
                'merged_into' => $canonical->id,
                'canonical_name' => $canonical->name,
                'employees_reassigned' => $employees->count(),
            ], 'Duplicate of shift #'.$canonical->id);

            // Archived, not deleted: history keeps resolving and the row stays
            // available if the merge ever has to be explained.
            if (! $duplicate->trashed()) {
                $duplicate->delete();
            }
        });

        $this->newLine();
        $this->info("Done. {$employees->count()} employee(s) moved to \"{$canonical->name}\"; \"{$duplicate->name}\" archived.");

        return self::SUCCESS;
    }

    private function scheduleMismatch(ShiftSetting $a, ShiftSetting $b): bool
    {
        return $this->window($a) !== $this->window($b)
            || (int) ($a->grace_minutes ?? 0) !== (int) ($b->grace_minutes ?? 0);
    }

    private function window(ShiftSetting $shift): string
    {
        return $this->time($shift->start_time).'-'.$this->time($shift->end_time);
    }

    private function time(mixed $value): string
    {
        return $value instanceof \DateTimeInterface
            ? $value->format('H:i')
            : substr((string) $value, 0, 5);
    }
}
