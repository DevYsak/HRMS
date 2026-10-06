<?php

namespace App\Services\DataTransfer;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\SpreadsheetService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The Import / Export centre's downloads. Every dataset is limited to the
 * employees inside the actor's reach (company-wide for unscoped HR), carries
 * no bank, tax-ID or salary columns, and every download is audited.
 */
class DataExportService
{
    /** Longest date range one attendance or leave-request export may span. */
    public const MAX_RANGE_DAYS = 366;

    /**
     * @var array<string, array{label: string, description: string, filter: 'none'|'range'|'year'}>
     */
    public const DATASETS = [
        'employees' => ['label' => 'Employees', 'description' => 'Directory: codes, departments, managers, joining dates, status', 'filter' => 'none'],
        'leave_balances' => ['label' => 'Leave balances', 'description' => 'Allocated, used, available and pending days per leave type', 'filter' => 'year'],
        'leave_requests' => ['label' => 'Leave requests', 'description' => 'Every request overlapping the chosen dates, with its status', 'filter' => 'range'],
        'attendance' => ['label' => 'Attendance', 'description' => 'Daily check-in / check-out, hours and status', 'filter' => 'range'],
        'holidays' => ['label' => 'Holidays', 'description' => 'The holiday list for a year, in the import layout', 'filter' => 'year'],
    ];

    public function __construct(private SpreadsheetService $spreadsheets) {}

    /**
     * Build and stream one dataset.
     *
     * @param  array{from?: ?string, to?: ?string, year?: ?int}  $filters
     *
     * @throws \InvalidArgumentException
     */
    public function download(string $dataset, User $actor, array $filters, string $format = 'xlsx'): BinaryFileResponse
    {
        [$headings, $rows] = $this->build($dataset, $actor, $filters);

        app(AuditService::class)->event('DATA_EXPORTED', AuditService::EXPORTS, $actor,
            new: ['dataset' => $dataset, 'rows' => count($rows), 'format' => $format, 'filters' => array_filter($filters)]);

        $extension = $format === 'csv' ? 'csv' : 'xlsx';

        return $this->spreadsheets->download($headings, $rows, str_replace('_', '-', $dataset).'-'.now()->format('Y-m-d').'.'.$extension);
    }

    /**
     * The heading row and data rows for a dataset.
     *
     * @param  array{from?: ?string, to?: ?string, year?: ?int}  $filters
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     *
     * @throws \InvalidArgumentException
     */
    public function build(string $dataset, User $actor, array $filters): array
    {
        return match ($dataset) {
            'employees' => $this->employees($actor),
            'leave_balances' => $this->leaveBalances($actor, $filters['year'] ?? null),
            'leave_requests' => $this->leaveRequests($actor, ...$this->range($filters)),
            'attendance' => $this->attendance($actor, ...$this->range($filters)),
            'holidays' => $this->holidays((int) ($filters['year'] ?? now()->year)),
            default => throw new \InvalidArgumentException("Unknown dataset: {$dataset}"),
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     *
     * @throws \InvalidArgumentException
     */
    private function range(array $filters): array
    {
        try {
            $from = Carbon::parse($filters['from'] ?? now()->startOfMonth())->startOfDay();
            $to = Carbon::parse($filters['to'] ?? now())->startOfDay();
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Enter valid From and To dates.');
        }

        if ($to->lt($from)) {
            throw new \InvalidArgumentException('The To date must be on or after the From date.');
        }

        if ($from->diffInDays($to) >= self::MAX_RANGE_DAYS) {
            throw new \InvalidArgumentException('Export at most '.self::MAX_RANGE_DAYS.' days at a time.');
        }

        return [$from, $to];
    }

    /** Restrict a query on an employee_id column to the actor's reach. */
    private function inReach(Builder $query, User $actor, string $column = 'employee_id'): Builder
    {
        $ids = $actor->accessibleEmployeeIds();

        return $query->when($ids !== null, fn (Builder $q) => $q->whereIn($column, $ids));
    }

    /** @return array{0: array<int, string>, 1: array<int, array<int, mixed>>} */
    private function employees(User $actor): array
    {
        $headings = ['Employee Code', 'Employee ID', 'Name', 'Email', 'Phone', 'Department', 'Job Title', 'Manager', 'Office', 'Shift', 'Employment Type', 'Joining Date', 'Status'];

        $rows = $this->inReach(Employee::query(), $actor, 'id')
            ->with(['user', 'department', 'jobTitle', 'manager', 'office', 'shift', 'employmentType'])
            ->orderBy('employee_code')
            ->get()
            ->map(fn (Employee $e): array => [
                $e->employee_code,
                $e->employee_id,
                $e->user?->name,
                $e->user?->email,
                $e->phone,
                $e->department?->name,
                $e->jobTitle?->name,
                $e->manager?->name,
                $e->office?->name,
                $e->shift?->name,
                $e->employmentType?->name,
                $e->joining_date ? Carbon::parse($e->joining_date)->toDateString() : null,
                is_object($e->status) ? $e->status->value : $e->status,
            ])->all();

        return [$headings, $rows];
    }

    /** @return array{0: array<int, string>, 1: array<int, array<int, mixed>>} */
    private function leaveBalances(User $actor, ?int $year): array
    {
        $headings = ['Employee Code', 'Name', 'Leave Type', 'Year', 'Allocated', 'Carried Forward', 'Used', 'Encashed', 'Available', 'Pending'];

        $rows = $this->inReach(LeaveBalance::query(), $actor)
            ->with(['employee.user', 'leaveType'])
            ->when($year, fn (Builder $q) => $q->where('year', $year))
            ->orderBy('employee_id')->orderBy('leave_type_id')
            ->get()
            ->map(fn (LeaveBalance $b): array => [
                $b->employee?->employee_code,
                $b->employee?->user?->name,
                $b->leaveType?->name,
                $b->year,
                (float) $b->allocated_days,
                (float) $b->carried_forward_days,
                $b->used_days_unknown ? 'Unknown' : (float) $b->used_days,
                (float) $b->encashed_days,
                // Unfloored: an overdraw must stay visible.
                $b->realAvailable(),
                $b->pendingDays(),
            ])->all();

        return [$headings, $rows];
    }

    /** @return array{0: array<int, string>, 1: array<int, array<int, mixed>>} */
    private function leaveRequests(User $actor, Carbon $from, Carbon $to): array
    {
        $headings = ['Employee Code', 'Name', 'Leave Type', 'Start Date', 'End Date', 'Days', 'Half Day', 'Status', 'Reason', 'Applied On', 'Decided By'];

        $rows = $this->inReach(LeaveRequest::query(), $actor)
            ->with(['employee.user', 'leaveType', 'reviewer'])
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->orderBy('start_date')
            ->get()
            ->map(fn (LeaveRequest $r): array => [
                $r->employee?->employee_code,
                $r->employee?->user?->name,
                $r->leaveType?->name,
                Carbon::parse($r->start_date)->toDateString(),
                Carbon::parse($r->end_date)->toDateString(),
                (float) $r->days,
                $r->is_half_day ? ucfirst((string) ($r->half_day_period ?: 'Yes')) : 'No',
                ucfirst(str_replace('_', ' ', (string) $r->status)),
                $r->reason,
                $r->created_at?->toDateString(),
                $r->reviewer?->name,
            ])->all();

        return [$headings, $rows];
    }

    /** @return array{0: array<int, string>, 1: array<int, array<int, mixed>>} */
    private function attendance(User $actor, Carbon $from, Carbon $to): array
    {
        $headings = ['Employee Code', 'Name', 'Date', 'Check In', 'Check Out', 'Total Hours', 'Status', 'Work Mode', 'Late Minutes', 'Regularised', 'Missing Check-out'];

        $rows = $this->inReach(Attendance::query(), $actor)
            ->with('employee.user')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')->orderBy('employee_id')
            ->get()
            ->map(fn (Attendance $a): array => [
                $a->employee?->employee_code,
                $a->employee?->user?->name,
                $a->date?->toDateString(),
                $a->check_in?->format('H:i'),
                $a->check_out?->format('H:i'),
                $a->total_hours !== null ? (float) $a->total_hours : null,
                ucfirst(str_replace('_', ' ', (string) $a->status)),
                is_object($a->work_mode) ? $a->work_mode->value : $a->work_mode,
                (int) $a->late_minutes,
                $a->is_regularized ? 'Yes' : 'No',
                $a->missing_checkout ? 'Yes' : 'No',
            ])->all();

        return [$headings, $rows];
    }

    /**
     * Holidays in the same layout the holiday import reads, so a year can be
     * exported, edited and imported for the next.
     *
     * @return array{0: array<int, string>, 1: array<int, array<int, mixed>>}
     */
    private function holidays(int $year): array
    {
        $rows = PublicHoliday::query()
            ->whereYear('date', $year)
            ->orderBy('date')
            ->get()
            ->map(fn (PublicHoliday $h): array => [
                $h->name,
                $h->date->toDateString(),
                $h->holiday_type?->value,
                $h->category,
                $h->country,
                $h->is_paid ? 'Yes' : 'No',
                $h->is_optional ? 'Yes' : 'No',
                $h->is_recurring ? 'Yes' : 'No',
                $h->description,
            ])->all();

        return [HolidayImportService::HEADINGS, $rows];
    }
}
