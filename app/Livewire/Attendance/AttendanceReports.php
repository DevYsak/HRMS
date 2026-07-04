<?php

namespace App\Livewire\Attendance;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Office;
use App\Models\ShiftSetting;
use App\Services\AttendanceReportBuilder;
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

    public ?int $departmentId = null;

    public ?int $officeId = null;

    public ?int $shiftId = null;

    public ?int $employeeId = null;

    public string $mode = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->canApproveLeave(), 403);
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
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
        abort_unless(Auth::user()->canApproveLeave(), 403);

        $report = $builder->build($this->type, $this->filters());

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
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'offices' => Office::orderBy('name')->get(['id', 'name']),
            'shifts' => ShiftSetting::orderBy('name')->get(['id', 'name']),
            'employees' => Employee::whereHas('user')->with('user')->orderBy('id')->get(),
        ])->layout('layouts.app', ['title' => 'Attendance Reports']);
    }
}
