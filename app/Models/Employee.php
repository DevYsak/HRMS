<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id', 'employee_id', 'biometric_id',
    'phone', 'date_of_birth', 'gender', 'address', 'emergency_contact', 'photo',
    'office_id', 'department_id', 'job_title_id', 'manager_id',
    'joining_date', 'probation_end_date', 'probation_extension_reason',
    'status', 'employment_type', 'salary_cycle', 'shift_id',
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
            'joining_date' => 'date',
            'probation_end_date' => 'date',
            'date_of_birth' => 'date',
            'status' => EmployeeStatus::class,
            'employment_type' => EmploymentType::class,
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

    public function subordinates(): HasMany
    {
        // One user (the manager) can have many employees as subordinates
        return $this->hasMany(Employee::class, 'manager_id', 'user_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(ShiftSetting::class, 'shift_id');
    }
}
