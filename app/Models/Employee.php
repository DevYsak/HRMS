<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    // Identity
    'user_id', 'employee_id', 'biometric_id',
    'employee_code', 'biometric_user_id', 'biometric_device_id', 'sync_status', 'last_biometric_sync_at',
    // Personal
    'phone', 'date_of_birth', 'gender', 'address', 'emergency_contact', 'photo',
    // Placement
    'office_id', 'department_id', 'job_title_id', 'manager_id',
    // Employment (Phase 1A FKs)
    'employment_type_id', 'work_mode_id', 'salary_cycle_id',
    // Legacy string columns kept for backward compat during migration
    'employment_type', 'salary_cycle',
    // Shift & OT source
    'shift_id', 'ot_tracking_source',
    // Joining & probation
    'joining_date', 'probation_end_date', 'probation_extension_reason',
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
            // Dates
            'joining_date' => 'date',
            'date_of_birth' => 'date',
            'probation_end_date' => 'date',
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
            // Enums
            'status' => EmployeeStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
