<?php

namespace App\Models;

use App\Enums\HolidayType;
use App\Services\Attendance\HolidayResolver;
use Database\Factories\PublicHolidayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A holiday in the Holiday Management module. Beyond the original
 * date/name/country it now carries a type, scope (branch/department/specific
 * employees), pay flags, recurrence, colour and an archive flag. All new
 * columns are nullable/defaulted so the legacy isHoliday() API and every
 * attendance/leave/report consumer keep working unchanged.
 */
#[Fillable([
    'date', 'name', 'country', 'jurisdiction', 'holiday_type', 'category', 'color', 'description', 'source',
    'is_paid', 'is_optional', 'is_recurring', 'is_active',
    'office_id', 'department_id', 'applicable_employee_ids', 'created_by',
])]
class PublicHoliday extends Model
{
    /** @use HasFactory<PublicHolidayFactory> */
    use HasFactory;

    /** In-memory defaults so a bare create() reflects the DB defaults immediately. */
    protected $attributes = [
        'holiday_type' => 'national',
        'country' => 'IN',
        'is_paid' => true,
        'is_optional' => false,
        'is_recurring' => false,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_paid' => 'boolean',
            'is_optional' => 'boolean',
            'is_recurring' => 'boolean',
            'is_active' => 'boolean',
            'applicable_employee_ids' => 'array',
            'holiday_type' => HolidayType::class,
        ];
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Effective calendar colour — explicit override, else the type default. */
    public function displayColor(): string
    {
        return $this->color ?: ($this->holiday_type instanceof HolidayType
            ? $this->holiday_type->color()
            : HolidayType::National->color());
    }

    public function typeLabel(): string
    {
        return $this->holiday_type instanceof HolidayType
            ? $this->holiday_type->label()
            : ucfirst((string) $this->holiday_type);
    }

    /** @param  Builder<PublicHoliday>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Narrow to holidays that apply to a given employee: company-wide ones
     * (no scope) plus any matching the employee's office / department, and
     * any that explicitly list the employee. Country still applies.
     *
     * @param  Builder<PublicHoliday>  $query
     */
    public function scopeForEmployee(Builder $query, Employee $employee): void
    {
        $query->where(function (Builder $q) use ($employee) {
            $q->where(function (Builder $scope) use ($employee) {
                $scope->whereNull('office_id')->orWhere('office_id', $employee->office_id);
            })->where(function (Builder $scope) use ($employee) {
                $scope->whereNull('department_id')->orWhere('department_id', $employee->department_id);
            });
        });
    }

    /** Does this holiday apply to the given employee (scope + explicit list)? */
    public function appliesToEmployee(Employee $employee): bool
    {
        if ($this->office_id && $this->office_id !== $employee->office_id) {
            return false;
        }
        if ($this->department_id && $this->department_id !== $employee->department_id) {
            return false;
        }
        if (! empty($this->applicable_employee_ids) && ! in_array($employee->id, $this->applicable_employee_ids, true)) {
            return false;
        }

        return true;
    }

    /**
     * Check if a given date is a public holiday for a country.
     * Unchanged signature — only counts active holidays now.
     */
    public static function isHoliday(Carbon $date, string $country = 'IN'): bool
    {
        return static::query()
            ->where('is_active', true)
            ->where('country', $country)
            ->where('date', $date->toDateString())
            ->exists();
    }

    /**
     * Whether the given date is a holiday that applies to a specific employee.
     *
     * Delegates to HolidayResolver, which adds the employee's calendar to the
     * branch/department/employee scope this used to check on its own. Without
     * the country test, a UK bank holiday blocked an India employee's leave.
     */
    public static function holidayForEmployeeOn(Carbon $date, Employee $employee): ?self
    {
        return app(HolidayResolver::class)->forEmployeeOn($employee, $date);
    }
}
