<?php

namespace App\Services;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\ProbationSetting;
use App\Models\User;
use App\Notifications\ProbationConfirmedNotification;
use App\Notifications\ProbationExtendedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProbationEngine
{
    /** Resolve probation days for an employee — per-type setting → type default → global default. */
    public function resolveProbationDays(Employee $employee): int
    {
        $typeId = $employee->employment_type_id;

        $setting = ProbationSetting::where('employment_type_id', $typeId)
            ->where('is_active', true)
            ->first()
            ?? ProbationSetting::whereNull('employment_type_id')
                ->where('is_active', true)
                ->first();

        return $setting?->probation_days ?? $employee->employmentType?->probation_days ?? 90;
    }

    /** Calculate and (optionally) persist probation end date from joining date. */
    public function calculateEndDate(Employee $employee): Carbon
    {
        return Carbon::parse($employee->joining_date)->addDays($this->resolveProbationDays($employee));
    }

    /** Auto-set probation_end_date if the setting allows it and the date is not yet set. */
    public function autoSetIfEnabled(Employee $employee): void
    {
        $typeId = $employee->employment_type_id;

        $setting = ProbationSetting::where('employment_type_id', $typeId)
            ->where('is_active', true)
            ->first()
            ?? ProbationSetting::whereNull('employment_type_id')
                ->where('is_active', true)
                ->first();

        if (! ($setting?->auto_calculate ?? true)) {
            return;
        }

        $endDate = $this->calculateEndDate($employee);
        Employee::withoutEvents(fn () => $employee->update(['probation_end_date' => $endDate]));
    }

    /** Step 1: Manager confirms probation completion. */
    public function managerConfirm(Employee $employee, User $manager, ?string $comment = null): void
    {
        abort_unless(
            \in_array($manager->role?->value, ['manager', 'director', 'super_admin'], true),
            403,
            'Only managers, directors, or super admins can confirm probation.'
        );

        if ($employee->probation_confirmed_at) {
            throw new \DomainException('Manager has already confirmed this probation.');
        }

        $employee->update([
            'probation_confirmed_by' => $manager->id,
            'probation_confirmed_at' => now(),
            'probation_extension_reason' => $comment ?? $employee->probation_extension_reason,
        ]);

        // Notify all HR admins to perform the second-stage sign-off
        User::whereIn('role', ['hr_admin', 'super_admin'])
            ->each(fn (User $hr) => $hr->notify(new ProbationConfirmedNotification($employee->fresh(), 'manager_confirmed')));
    }

    /** Step 2: HR Admin co-approves and moves employee to Confirmed status. */
    public function hrApprove(Employee $employee, User $hr, ?string $comment = null): void
    {
        abort_unless(
            \in_array($hr->role?->value, ['hr_admin', 'super_admin'], true),
            403,
            'Only HR admins or super admins can approve probation.'
        );

        if (! $employee->probation_confirmed_at) {
            throw new \DomainException('Manager must confirm before HR can approve.');
        }

        if ($employee->probation_hr_approved_at) {
            throw new \DomainException('HR has already approved this probation.');
        }

        DB::transaction(function () use ($employee, $hr) {
            $employee->update([
                'probation_hr_approved_by' => $hr->id,
                'probation_hr_approved_at' => now(),
                'confirmed_at' => now(),
                'status' => EmployeeStatus::Confirmed,
            ]);
        });

        $employee->user->notify(new ProbationConfirmedNotification($employee->fresh(), 'hr_approved'));
    }

    /** Extend probation period with a reason. Notifies manager. */
    public function extend(Employee $employee, User $actor, string $newEndDate, string $reason): void
    {
        $setting = ProbationSetting::where('employment_type_id', $employee->employment_type_id)
            ->where('is_active', true)
            ->first()
            ?? ProbationSetting::whereNull('employment_type_id')->where('is_active', true)->first();

        $maxDays = $setting?->extension_max_days ?? 90;
        $maxDate = Carbon::parse($employee->probation_end_date ?? now())->addDays($maxDays);

        if (Carbon::parse($newEndDate)->gt($maxDate)) {
            throw new \DomainException("Extension cannot exceed {$maxDays} days past current probation end.");
        }

        $employee->update([
            'probation_end_date' => $newEndDate,
            'probation_extension_reason' => $reason,
            // Clear prior manager confirmation so the cycle restarts
            'probation_confirmed_by' => null,
            'probation_confirmed_at' => null,
            'probation_hr_approved_by' => null,
            'probation_hr_approved_at' => null,
        ]);

        if ($employee->manager) {
            $employee->manager->notify(new ProbationExtendedNotification($employee->fresh()));
        }
    }

    /** Whether the probation settings require manager review for this employee. */
    public function requiresManagerReview(Employee $employee): bool
    {
        $setting = ProbationSetting::where('employment_type_id', $employee->employment_type_id)
            ->where('is_active', true)
            ->first()
            ?? ProbationSetting::whereNull('employment_type_id')->where('is_active', true)->first();

        return $setting?->requires_manager_review ?? true;
    }

    /** Whether the probation settings require HR approval for this employee. */
    public function requiresHrApproval(Employee $employee): bool
    {
        $setting = ProbationSetting::where('employment_type_id', $employee->employment_type_id)
            ->where('is_active', true)
            ->first()
            ?? ProbationSetting::whereNull('employment_type_id')->where('is_active', true)->first();

        return $setting?->requires_hr_approval ?? true;
    }
}
