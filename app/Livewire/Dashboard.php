<?php

namespace App\Livewire;

use App\Enums\AttendanceMode;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Department;
use App\Models\Employee;
use App\Services\Attendance\ShiftResolver;
use App\Services\AttendanceService;
use App\Services\Dashboards\OrganisationOverview;
use App\Services\EmployeeDashboardService;
use App\Services\Navigation\DashboardLanding;
use App\Services\WfhService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Dashboard extends Component
{
    /**
     * Managers, Finance and Directors land on their own dashboard page.
     *
     * Each of those is a full-page component with its own layout and route
     * gate. Rendering one from here sent its approve / reject clicks to this
     * component, and mounting it as a child broke the app-shell grid — so
     * "/" forwards to the page itself.
     */
    public function mount(): void
    {
        $landing = app(DashboardLanding::class)->route(Auth::user());

        if ($landing !== null) {
            $this->redirectRoute($landing, navigate: true);
        }
    }

    /**
     * "/" renders the company overview (Super Admin), the HR overview, or
     * employee self-service — decided by permissions (DashboardLanding), so a
     * customised role without a dashboard page of its own stays on
     * self-service rather than being sent to a 403.
     */
    public function render()
    {
        return match (app(DashboardLanding::class)->view(Auth::user())) {
            DashboardLanding::COMPANY, DashboardLanding::HR => $this->renderHrAdmin(),
            default => $this->renderEmployee(),
        };
    }

    /**
     * The HR / Super Admin overview at "/": every figure from
     * OrganisationOverview over the viewer's reach (a department-scoped HR
     * user sees their departments), each shown once.
     */
    private function renderHrAdmin()
    {
        return view('dashboard', OrganisationOverview::viewData(Auth::user()))
            ->layout('layouts.app', ['title' => 'Dashboard']);
    }

    /**
     * Clock in from the dashboard.
     *
     * The button here has always been a link to the attendance page, so the
     * most common action in the product took two screens. It now punches
     * directly — through AttendanceService, the same call the attendance page
     * makes. No second engine: lateness, shift resolution and comp-off all stay
     * where they are.
     *
     * The exception is capture policy. When HR requires a selfie or a location
     * fix, the dashboard has no camera or geolocation prompt, and punching
     * without them would quietly defeat the control. Those employees are handed
     * to the attendance page, which does have the capture UI.
     */
    public function clockIn(?float $lat = null, ?float $lng = null, ?string $workMode = null): void
    {
        $employee = Auth::user()?->employee;

        if (! $employee) {
            \Flux::toast('No employee profile found. Contact HR.', variant: 'danger');

            return;
        }

        if ($this->punchNeedsCapture($lat, $lng)) {
            $this->redirect(route('attendance.my'), navigate: true);

            return;
        }

        // Already punched in today — do nothing rather than open a second day.
        if ($this->todayAttendanceFor($employee)) {
            return;
        }

        // Working from home is an approved arrangement, the same rule the
        // attendance page enforces. The card only offers WFH on an approved
        // day; this refuses a stale or forged call.
        if ($workMode === AttendanceMode::Wfh->value && ! app(WfhService::class)->isApprovedFor($employee, Carbon::today())) {
            \Flux::toast('You have no approved work-from-home request for today. Submit one under Work From Home, or clock in from the office.', variant: 'danger');

            return;
        }

        app(AttendanceService::class)->checkIn(
            $employee,
            $employee->shift ?? ShiftResolver::companyDefault(),
            [
                'ip' => request()->ip(), 'lat' => $lat, 'lng' => $lng,
                // Spec §3.2: chosen at clock-in. Only Office / WFH from the
                // dashboard; anything else falls back to Office in the service.
                'work_mode' => in_array($workMode, [AttendanceMode::Office->value, AttendanceMode::Wfh->value], true)
                    ? $workMode : AttendanceMode::Office->value,
            ],
        );

        \Flux::toast('Clocked in successfully.');
    }

    /** Clock out from the dashboard. Mirrors clockIn(); see its note. */
    public function clockOut(?float $lat = null, ?float $lng = null): void
    {
        $employee = Auth::user()?->employee;

        if (! $employee) {
            \Flux::toast('No employee profile found. Contact HR.', variant: 'danger');

            return;
        }

        if ($this->punchNeedsCapture($lat, $lng)) {
            $this->redirect(route('attendance.my'), navigate: true);

            return;
        }

        $attendance = $this->todayAttendanceFor($employee);

        if (! $attendance || $attendance->check_out) {
            return;
        }

        app(AttendanceService::class)->checkOut($attendance, ['ip' => request()->ip(), 'lat' => $lat, 'lng' => $lng]);

        \Flux::toast('Clocked out successfully. Good work today!');
    }

    /**
     * Whether HR requires evidence this screen cannot supply.
     *
     * The dashboard button asks the browser for a location, so a location
     * requirement is satisfiable here — but only if the browser actually
     * returned one. A selfie is not: the camera modal lives on the attendance
     * page, and punching without the photo would defeat the control rather
     * than enforce it. Either way the employee is handed to that page, which
     * can collect what is missing.
     */
    private function punchNeedsCapture(?float $lat, ?float $lng): bool
    {
        $settings = AttendanceSetting::first();

        if ($settings?->requires_photo) {
            return true;
        }

        return (bool) $settings?->requires_location && ($lat === null || $lng === null);
    }

    /**
     * Start a break from the dashboard — the same AttendanceService call the
     * attendance page's break button makes. Ignored once the day is closed.
     */
    public function startBreak(): void
    {
        $employee = Auth::user()?->employee;
        $attendance = $employee ? $this->todayAttendanceFor($employee) : null;

        if (! $attendance || $attendance->check_out) {
            return;
        }

        if (app(AttendanceService::class)->startBreak($attendance)) {
            \Flux::toast('Break started.');
        }
    }

    /** End the open break. Mirrors startBreak(). */
    public function endBreak(): void
    {
        $employee = Auth::user()?->employee;
        $attendance = $employee ? $this->todayAttendanceFor($employee) : null;

        if (! $attendance) {
            return;
        }

        if (app(AttendanceService::class)->endBreak($attendance)) {
            \Flux::toast('Break ended. Welcome back!');
        }
    }

    private function todayAttendanceFor(Employee $employee): ?Attendance
    {
        return Attendance::where('employee_id', $employee->id)
            ->whereDate('date', Carbon::today()->toDateString())
            ->first();
    }

    /**
     * Employee self-service dashboard.
     *
     * Every figure comes from EmployeeDashboardService, which reads the same
     * services the owning screens use (leave calculator, punch timeline, shift
     * resolver, holiday resolver) — this component only chooses the view.
     */
    private function renderEmployee()
    {
        $data = app(EmployeeDashboardService::class)->build(Auth::user());

        return view('livewire.employee-dashboard', $data)
            ->layout('layouts.app', ['title' => 'My Dashboard']);
    }
}
