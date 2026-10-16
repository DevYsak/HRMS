<?php

namespace App\Livewire\Attendance;

use App\Enums\DataScope;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\AttendanceReportBuilder;
use App\Services\Security\PeopleScope;
use App\Services\Security\ScopeResolver;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Enterprise Attendance Reports — a live preview + summary + trend for 13
 * report types with department / branch / shift / employee / mode filters.
 * Exports (CSV, PDF, print) reuse the same AttendanceReportBuilder via the
 * reports controller, so what you preview is exactly what you download.
 */
class AttendanceReports extends Component
{
    #[Url]
    public string $type = 'daily';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url(as: 'department')]
    public ?int $departmentId = null;

    public ?int $officeId = null;

    public ?int $shiftId = null;

    public ?int $employeeId = null;

    public string $mode = '';

    /**
     * Who may open Attendance Reports: holders of the dedicated read-only
     * view_attendance_reports permission (QA-F-021) whose scope reaches other
     * people — never inferred from approve_leave or view_attendance, never
     * someone limited to their own record. It grants no management power.
     */
    public static function canOpen(User $user): bool
    {
        return $user->hasPermission(self::PERMISSION)
            && ! in_array(app(ScopeResolver::class)->scopeFor($user, self::PERMISSION), [DataScope::Own, DataScope::None], true);
    }

    public const PERMISSION = 'view_attendance_reports';

    public function mount(): void
    {
        abort_unless(self::canOpen(Auth::user()), 403);
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();

        // One department in reach: it is the filter, shown locked.
        $options = app(PeopleScope::class)->departmentOptions(Auth::user(), self::permission());
        $this->departmentId = $options->count() === 1
            ? $options->first()->id
            : app(PeopleScope::class)->sanitiseDepartment(Auth::user(), self::permission(), $this->departmentId);
    }

    /** Whose attendance the reports cover: the view_attendance_reports scope. */
    public static function permission(): string
    {
        return self::PERMISSION;
    }

    /** @return array<int, int>|null */
    public static function reach(): ?array
    {
        return app(PeopleScope::class)->employeeIds(Auth::user(), self::permission(), includeSelf: true);
    }

    public function updatedDepartmentId(): void
    {
        // A tampered or out-of-reach department is dropped, never trusted.
        $this->departmentId = app(PeopleScope::class)->sanitiseDepartment(Auth::user(), self::permission(), $this->departmentId);
    }

    /** @return array<string,mixed> */
    protected function filters(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'department_id' => $this->departmentId,
            'office_id' => $this->officeId,
            'shift_id' => $this->shiftId,
            'employee_id' => $this->employeeId,
            'mode' => $this->mode,
        ];
    }

    /** Query string forwarded to the export routes. */
    public function getExportParamsProperty(): array
    {
        return array_merge(['type' => $this->type], array_filter($this->filters(), fn ($v) => $v !== null && $v !== ''));
    }

    public function render(AttendanceReportBuilder $builder)
    {
        abort_unless(self::canOpen(Auth::user()), 403);

        // The viewer's reach is applied server-side; never taken from the query string.
        $reach = self::reach();
        $report = $builder->build($this->type, $this->filters() + ($reach !== null ? ['employee_ids' => $reach] : []));

        // Preview is capped; the export streams the full set.
        $previewRows = array_slice($report['rows'], 0, 100);

        $trendChart = null;
        if (! empty($report['trend']['labels'])) {
            $trendChart = [
                'chart' => ['type' => 'area', 'height' => 200, 'toolbar' => ['show' => false], 'fontFamily' => 'inherit', 'animations' => ['enabled' => true, 'speed' => 600]],
                'colors' => ['#F97316'],
                'dataLabels' => ['enabled' => false],
                'stroke' => ['curve' => 'smooth', 'width' => 3],
                'fill' => ['type' => 'gradient', 'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.3, 'opacityTo' => 0.02, 'stops' => [0, 90]]],
                'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 4],
                'xaxis' => ['categories' => $report['trend']['labels'], 'labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']], 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false], 'tickAmount' => min(10, count($report['trend']['labels']))],
                'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]],
                'tooltip' => ['theme' => 'light'],
                'series' => [['name' => 'Count', 'data' => $report['trend']['data']]],
            ];
        }

        return view('livewire.attendance.attendance-reports', [
            'types' => AttendanceReportBuilder::TYPES,
            'report' => $report,
            'previewRows' => $previewRows,
            'totalRows' => count($report['rows']),
            'trendChart' => $trendChart,
            // Filters offer only what is inside the reach — never a department,
            // branch, shift or person the next request would leave out.
            'departments' => $departments = app(PeopleScope::class)->departmentOptions(Auth::user(), self::permission()),
            'lockedDepartment' => $departments->count() === 1 ? $departments->first() : null,
            'scope' => app(PeopleScope::class)->summary(Auth::user(), self::permission(), $this->departmentId ? [$this->departmentId] : null),
            'offices' => $reach === null
                ? Office::orderBy('name')->get(['id', 'name'])
                : Office::whereIn('id', Employee::whereIn('id', $reach)->whereNotNull('office_id')->distinct()->pluck('office_id'))->orderBy('name')->get(['id', 'name']),
            'shifts' => $reach === null
                ? ShiftSetting::orderBy('name')->get(['id', 'name'])
                : ShiftSetting::whereIn('id', Employee::whereIn('id', $reach)->whereNotNull('shift_id')->distinct()->pluck('shift_id'))->orderBy('name')->get(['id', 'name']),
            // Only people inside the viewer's reach: a scoped manager or HR
            // user must not see every employee's name in the picker.
            'employees' => Employee::whereHas('user')->with('user')
                ->when($reach !== null, fn ($q) => $q->whereIn('id', $reach))
                ->when($this->departmentId, fn ($q) => $q->where('department_id', $this->departmentId))
                ->orderBy('id')->get(),
        ])->layout('layouts.app', ['title' => 'Attendance Reports']);
    }
}
