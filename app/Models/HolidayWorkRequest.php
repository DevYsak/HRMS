<?php

namespace App\Models;

use Database\Factories\HolidayWorkRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request to work on a company holiday. On approval, HolidayWorkService
 * materialises a holiday-worked attendance plus the chosen pay (overtime or
 * comp-off). Mirrors AttendanceRegularisation's review lifecycle.
 */
#[Fillable([
    'employee_id', 'holiday_id', 'work_date', 'reason', 'work_location',
    'expected_hours', 'project', 'manager_id', 'comments', 'attachment_path',
    'pay_type', 'status', 'reviewer_id', 'reviewer_comment', 'reviewed_at', 'attendance_id',
])]
class HolidayWorkRequest extends Model
{
    /** @use HasFactory<HolidayWorkRequestFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'expected_hours' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function holiday(): BelongsTo
    {
        return $this->belongsTo(PublicHoliday::class, 'holiday_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function payTypeLabel(): string
    {
        return match ($this->pay_type) {
            'comp_off' => 'Comp Off',
            'double_pay' => 'Double Pay',
            'extra_leave' => 'Extra Leave',
            'half_day' => 'Half Day',
            default => 'Overtime',
        };
    }

    public function locationLabel(): string
    {
        return match ($this->work_location) {
            'wfh' => 'Work From Home',
            'client_site' => 'Client Site',
            default => 'Office',
        };
    }
}
