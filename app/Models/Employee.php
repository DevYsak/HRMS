<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Services\Attendance\ShiftResolver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    // Identity
    'user_id', 'employee_id', 'biometric_id',
    'employee_code', 'has_placeholder_email', 'biometric_user_id', 'biometric_device_id', 'sync_status', 'last_biometric_sync_at',
    // Personal
    'phone', 'date_of_birth', 'gender', 'address', 'emergency_contact', 'photo',
    // Placement
    'office_id', 'holiday_calendar', 'leave_policy_id', 'working_pattern',
    'working_days_per_week', 'contracted_hours_per_week', 'working_days',
    'department_id', 'job_title_id', 'manager_id',
    // Employment (Phase 1A FKs)
    'employment_type_id', 'work_mode_id', 'salary_cycle_id',
    // Legacy string columns kept for backward compat during migration
    'employment_type', 'salary_cycle',
    // A salary-cycle move waiting for its effective payroll month
    'pending_salary_cycle_id', 'salary_cycle_effective_month', 'salary_cycle_paid_through',
    // Shift & OT source
    'shift_id', 'ot_tracking_source',
    // Joining & probation
    'joining_date', 'probation_end_date', 'probation_reminder_sent_for', 'probation_extension_reason',
    'onboarding_completed_notified_at', 'offboarding_completed_notified_at', 'newhire_checkin_notified_at',
    'probation_confirmed_by', 'probation_confirmed_at', 'probation_hr_approved_by', 'probation_hr_approved_at',
    // Lifecycle status & dates (Phase 1A)
    'status',
    'resignation_date', 'termination_date', 'notice_period_end_date',
    'absconded_at', 'confirmed_at', 'archived_at',
])]
class Employee extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * Login invitations issued for this employee, newest first. Kept as
     * history rather than a single column: a resend supersedes the previous
     * invitation but does not erase that it was sent.
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(EmployeeInvitation::class)->orderByDesc('id');
    }

    /**
     * The invitation that currently governs this employee's access. Everything
     * older has been revoked by a resend, so only the newest one is live.
     */
    public function latestInvitation(): HasOne
    {
        return $this->hasOne(EmployeeInvitation::class)->latestOfMany();
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function salaries(): HasMany
    {
        return $this->hasMany(EmployeeSalary::class);
    }

    public function payrollSettings(): HasOne
    {
        return $this->hasOne(EmployeePayrollSettings::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function performanceReviews(): HasMany
    {
        return $this->hasMany(PerformanceReview::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(ReviewGoal::class);
    }

    public function otRequests(): HasMany
    {
        return $this->hasMany(OtRequest::class);
    }

    public function wfhRequests(): HasMany
    {
        return $this->hasMany(WfhRequest::class);
    }

    public function overtimeRecords(): HasMany
    {
        return $this->hasMany(OvertimeRecord::class);
    }

    public function onboardingTasks(): HasMany
    {
        return $this->hasMany(OnboardingTask::class);
    }

    public function equipmentLogs(): HasMany
    {
        return $this->hasMany(EquipmentLog::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function exitRecord(): HasOne
    {
        return $this->hasOne(ExitRecord::class);
    }

    public function regularisations(): HasMany
    {
        return $this->hasMany(AttendanceRegularisation::class);
    }

    protected function casts(): array
    {
        return [
            // Which weekdays this employee works, as ISO numbers (1 = Monday).
            'working_days' => 'array',
            'working_days_per_week' => 'decimal:1',
            'contracted_hours_per_week' => 'decimal:2',

            // Dates
            'joining_date' => 'date',
            'date_of_birth' => 'date',
            'probation_end_date' => 'date',
            'salary_cycle_paid_through' => 'date',
            'probation_reminder_sent_for' => 'date',
            'onboarding_completed_notified_at' => 'datetime',
            'offboarding_completed_notified_at' => 'datetime',
            'newhire_checkin_notified_at' => 'datetime',
            'resignation_date' => 'date',
            'termination_date' => 'date',
            'notice_period_end_date' => 'date',
            // Datetimes
            'probation_confirmed_at' => 'datetime',
            'probation_hr_approved_at' => 'datetime',
            'last_biometric_sync_at' => 'datetime',
            'absconded_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'archived_at' => 'datetime',
            // Primitives
            'employee_code' => 'integer',
            'has_placeholder_email' => 'boolean',
            // Enums
            'status' => EmployeeStatus::class,
        ];
    }

    /**
     * Imported from an HR sheet that had no joining date. Probation, leave
     * accrual and payroll proration all key off this date, so they skip the
     * employee until HR fills it in rather than computing from a guess.
     */
    public function isMissingJoiningDate(): bool
    {
        return $this->joining_date === null;
    }

    /** Employment fields HR owns, with the label HR sees. */
    public const HR_PROFILE_FIELDS = [
        'department_id' => 'Department',
        'job_title_id' => 'Designation',
        'manager_id' => 'Reporting manager',
        'shift' => 'Shift',
        'joining_date' => 'Joining date',
        'employment_type_id' => 'Employment type',
        'work_mode_id' => 'Work mode',
        'salary_cycle_id' => 'Salary cycle',
    ];

    /**
     * Employment data HR has not recorded yet, as field => label.
     *
     * Nothing is ever filled in to make this list shorter: a missing value is
     * a task for HR (the completion queue on Employee Management), never a
     * default. Top-of-hierarchy accounts (Super Admin, Director) legitimately
     * have no reporting manager, and probation end only matters on probation.
     *
     * @return array<string, string>
     */
    public function missingHrFields(): array
    {
        $missing = [];

        foreach (self::HR_PROFILE_FIELDS as $field => $label) {
            $absent = match ($field) {
                'shift' => ! ShiftResolver::hasResolvableShift($this),
                'manager_id' => $this->manager_id === null && ! $this->isTopOfHierarchy(),
                default => blank($this->{$field}),
            };

            if ($absent) {
                $missing[$field] = $label;
            }
        }

        if ($this->status === EmployeeStatus::Probation && $this->probation_end_date === null) {
            $missing['probation_end_date'] = 'Probation end date';
        }

        return $missing;
    }

    public function hasIncompleteHrProfile(): bool
    {
        return $this->missingHrFields() !== [];
    }

    /**
     * Employees missing any HR-owned employment field — the HR completion
     * queue. The SQL mirror of missingHrFields() (shift resolved the same way:
     * no own shift and no company default).
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function scopeIncompleteHrProfile(Builder $query): Builder
    {
        $noDefaultShift = ShiftResolver::companyDefault() === null;

        return $query->where(function (Builder $q) use ($noDefaultShift) {
            $q->whereNull('department_id')
                ->orWhereNull('job_title_id')
                ->orWhereNull('joining_date')
                ->orWhereNull('employment_type_id')
                ->orWhereNull('work_mode_id')
                ->orWhereNull('salary_cycle_id')
                ->orWhere(fn (Builder $p) => $p->where('status', EmployeeStatus::Probation->value)->whereNull('probation_end_date'))
                ->orWhere(fn (Builder $m) => $m->whereNull('manager_id')
                    ->whereHas('user', fn ($u) => $u->whereNotIn('role', [UserRole::SuperAdmin->value, UserRole::Director->value])));

            if ($noDefaultShift) {
                $q->orWhereNull('shift_id');
            }
        });
    }

    /** Super Admins and Directors sit at the top: no reporting manager is expected. */
    private function isTopOfHierarchy(): bool
    {
        return in_array($this->user?->role, [UserRole::SuperAdmin, UserRole::Director], true);
    }

    /**
     * True when this employee still needs real HR data before every feature
     * works — surfaced on the record and in the import validation report.
     *
     * @return array<int, string>
     */
    public function dataFlags(): array
    {
        return array_values(array_filter([
            $this->has_placeholder_email ? 'Email Pending' : null,
            $this->isMissingJoiningDate() ? 'Joining Date Missing' : null,
            // Without a resolvable shift there is no window to judge arrivals
            // against, so the attendance engine declines to score the day at
            // all. That is safe but invisible — this makes it a visible task.
            ShiftResolver::hasResolvableShift($this) ? null : 'Shift Not Assigned',
        ]));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }

    /**
     * The holiday policy that decides this employee's entitlement.
     *
     * Separate from shift, employment type and working pattern on purpose —
     * each answers a different question and none is derivable from another.
     */
    public function leavePolicy(): BelongsTo
    {
        return $this->belongsTo(LeavePolicy::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function probationConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'probation_confirmed_by');
    }

    public function probationHrApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'probation_hr_approved_by');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id', 'user_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(ShiftSetting::class, 'shift_id');
    }

    /** Active team memberships (v4 Part 3 — at most one active at a time). */
    public function teamMemberships(): HasMany
    {
        return $this->hasMany(DepartmentTeamMember::class);
    }

    /** The employee's current department team (via the active membership), or null. */
    public function activeTeam(): ?DepartmentTeam
    {
        return $this->teamMemberships()
            ->where('is_active', true)
            ->latest('id')
            ->first()?->team;
    }

    public function biometricDevice(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'biometric_device_id');
    }

    // ── Phase 1A — Dynamic FK relationships ──────────────────────────────────

    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class)->withTrashed();
    }

    public function workMode(): BelongsTo
    {
        return $this->belongsTo(WorkMode::class)->withTrashed();
    }

    public function salaryCycle(): BelongsTo
    {
        return $this->belongsTo(SalaryCycle::class)->withTrashed();
    }

    /** The cycle this employee moves to at salary_cycle_effective_month. */
    public function pendingSalaryCycle(): BelongsTo
    {
        return $this->belongsTo(SalaryCycle::class, 'pending_salary_cycle_id')->withTrashed();
    }

    // ── Lifecycle helpers ─────────────────────────────────────────────────────

    /** True when biometric enrolment is confirmed and ready for attendance mapping. */
    public function isBiometricReady(): bool
    {
        return $this->employee_code !== null && $this->sync_status === 'synced';
    }

    /** True when both manager and HR have signed off on probation. */
    public function isProbationFullyConfirmed(): bool
    {
        return $this->probation_confirmed_at !== null
            && $this->probation_hr_approved_at !== null;
    }

    /** True when probation period has elapsed without confirmation action. */
    public function isProbationOverdue(): bool
    {
        return $this->status === EmployeeStatus::Probation
            && $this->probation_end_date !== null
            && $this->probation_end_date->isPast()
            && ! $this->isProbationFullyConfirmed();
    }

    /** True for headcount — employee is actively working. */
    public function isActiveHeadcount(): bool
    {
        return $this->status->isActive();
    }

    /** Effective probation days from the linked employment type or system default. */
    public function probationDays(): int
    {
        return $this->employmentType?->probationSetting?->probation_days
            ?? $this->employmentType?->probation_days
            ?? 90;
    }

    // ── Performance relations ─────────────────────────────────────────────────

    public function employeeKpis(): HasMany
    {
        return $this->hasMany(EmployeeKpi::class);
    }

    public function scorecards(): HasMany
    {
        return $this->hasMany(EmployeeScorecard::class);
    }

    public function warningLetters(): HasMany
    {
        return $this->hasMany(WarningLetter::class);
    }

    public function pipRecords(): HasMany
    {
        return $this->hasMany(PipRecord::class);
    }

    public function promotionRecommendations(): HasMany
    {
        return $this->hasMany(PromotionRecommendation::class);
    }

    public function performanceTimelines(): HasMany
    {
        return $this->hasMany(PerformanceTimeline::class)->orderByDesc('event_date');
    }

    public function activeWarnings(): HasMany
    {
        return $this->hasMany(WarningLetter::class)->whereIn('status', ['issued', 'acknowledged', 'under_review']);
    }

    public function activePip(): HasOne
    {
        return $this->hasOne(PipRecord::class)->whereIn('status', ['active', 'under_review'])->latestOfMany();
    }
}
