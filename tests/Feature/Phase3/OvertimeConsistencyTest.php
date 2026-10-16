<?php

use App\Livewire\ManagerDashboard;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\Role;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Ai\AiDataTools;
use App\Services\EmployeeDashboardService;
use App\Services\OvertimeService;
use App\Services\People\Employee360Service;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * P3-03: four overtime figures, never merged, each labelled. October 2026,
 * shift 09:00–18:00:
 *  - Mon 12: worked 09:00–21:00 → 3h actual worked beyond the shift;
 *    approved request for 2h; payroll measured 2.5h payable (₹500).
 *  - Tue 13: pending request for 1.5h.
 * Requested 3.5 · approved requested 2 · actual worked 3 · payable 2.5.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 20:00'));
    $shift = ShiftSetting::create(['name' => 'Day', 'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 0, 'is_default' => true]);
    $dept = Department::factory()->create();
    $managerRole = Role::where('slug', 'manager')->firstOrFail();
    $this->manager = User::factory()->create(['role' => $managerRole->legacyBucket(), 'role_id' => $managerRole->id]);
    Employee::factory()->create(['user_id' => $this->manager->id, 'department_id' => $dept->id, 'status' => 'active', 'manager_id' => null]);
    $this->hr = User::factory()->create(['role' => 'hr_admin', 'role_id' => Role::where('slug', 'hr_admin')->value('id')]);
    Employee::factory()->create(['user_id' => $this->hr->id, 'department_id' => $dept->id, 'status' => 'active', 'manager_id' => null]);

    $role = Role::where('slug', 'employee')->firstOrFail();
    $this->user = User::factory()->create(['name' => 'Otto Overtime', 'role' => $role->legacyBucket(), 'role_id' => $role->id]);
    $this->employee = Employee::factory()->create(['user_id' => $this->user->id, 'department_id' => $dept->id, 'status' => 'active', 'manager_id' => $this->manager->id, 'shift_id' => $shift->id, 'joining_date' => '2026-01-01']);

    $attendance = Attendance::create(['employee_id' => $this->employee->id, 'date' => '2026-10-12', 'check_in' => Carbon::parse('2026-10-12 09:00'), 'check_out' => Carbon::parse('2026-10-12 21:00'), 'status' => 'on_time', 'work_mode' => 'office']);
    $approved = OtRequest::create(['employee_id' => $this->employee->id, 'attendance_id' => $attendance->id, 'work_date' => '2026-10-12', 'start_time' => '18:00', 'end_time' => '20:00', 'requested_hours' => 2, 'reason' => 'Release', 'status' => 'approved']);
    OtRequest::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-13', 'start_time' => '18:00', 'end_time' => '19:30', 'requested_hours' => 1.5, 'reason' => 'Support', 'status' => 'pending']);
    OtRequest::create(['employee_id' => $this->employee->id, 'work_date' => '2026-10-13', 'start_time' => '19:30', 'end_time' => '20:30', 'requested_hours' => 1, 'reason' => 'Withdrawn', 'status' => 'cancelled']);
    OvertimeRecord::query()->forceCreate(['employee_id' => $this->employee->id, 'ot_request_id' => $approved->id, 'attendance_id' => $attendance->id, 'work_date' => '2026-10-12',
        'total_hours_worked' => 12, 'standard_hours' => 9, 'ot_hours' => 2.5, 'ot_amount' => 500, 'is_paid' => false]);
});

test('the service keeps the four figures apart', function () {
    $hours = app(OvertimeService::class)->hours([$this->employee->id], Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), withActual: true);

    expect([$hours['requested_hours'], $hours['approved_requested_hours'], $hours['actual_worked_hours'], $hours['payable_hours'], $hours['payable_amount']])
        ->toBe([3.5, 2.0, 3.0, 2.5, 500.0])
        ->and(OvertimeService::LABELS)->toBe(['requested_hours' => 'Requested OT', 'approved_requested_hours' => 'Approved requested OT', 'actual_worked_hours' => 'Actual worked OT', 'payable_hours' => 'Payable OT']);
});

test('Payable OT reads the same on the employee dashboard, My Overtime, AI, Team View and Employee 360', function () {
    $dashboard = app(EmployeeDashboardService::class)->build($this->user);
    $kpi = collect($dashboard['kpis'])->firstWhere('label', 'Payable OT');
    expect($kpi['value'])->toBe('2.5h');

    expect(app(OvertimeService::class)->summaryFor($this->employee)['approved_hours_month'])->toBe(2.5)
        ->and(app(AiDataTools::class)->myOvertime($this->user)['approved_hours_month'])->toBe(2.5);

    Livewire::actingAs($this->manager)->test(ManagerDashboard::class)
        ->assertViewHas('teamOtHours', 2.5)->assertSee('Payable OT');

    $ot = app(Employee360Service::class)->build($this->hr, $this->employee, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-14'))['overtime'];
    expect([$ot['payable_hours'], $ot['approved_requested_hours'], $ot['actual_worked_hours'], $ot['requested_hours']])->toBe([2.5, 2.0, 3.0, 3.5]);
});

test('the OT reports label which figure each column holds', function () {
    $monthly = $this->actingAs($this->hr)->get(route('reports.monthly-ot', ['month' => 10, 'year' => 2026]))->assertOk()->streamedContent();
    $lines = collect(preg_split('/\R/', trim($monthly)))->map(fn ($l) => str_getcsv($l));
    $row = array_combine($lines->first(), $lines->firstWhere(2, 'Otto Overtime'));
    expect([(float) $row['Requested OT Hours'], (float) $row['Approved Requested OT Hours'], (float) $row['Payable OT Hours']])->toBe([3.5, 2.0, 2.5]);

    $records = $this->actingAs($this->hr)->get(route('reports.ot-records', ['month' => 10, 'year' => 2026]))->assertOk()->streamedContent();
    expect($records)->toContain('Payable OT Hours')->not->toContain(',OT Hours,');
});
