<?php

use App\Livewire\ManagerDashboard;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Ai\AiDataTools;
use App\Services\Attendance\AttendanceWindowService;
use App\Services\Attendance\TodayStatusService;
use App\Services\AttendanceReportBuilder;
use App\Services\People\Employee360Service;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * P3-01: one seeded attendance scenario, classified the same way by Team
 * View, AI, Employee 360, the Monthly Attendance report and the Attendance
 * Compliance CSV. Wed 14 Oct 2026, shift 09:00–18:00, no grace:
 *  - Ava:  Mon on time · Tue nothing (absent) · Wed 09:40 (late — the row
 *          still says "on_time": the calculator decides, not a stale flag)
 *  - Ben:  Mon on time · Tue approved leave (never absent) · Wed on time
 */
function acUser(string $slug, Department $department, string $name, array $employee = []): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['name' => $name, 'role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null, ...$employee]);

    return $user->fresh();
}

function acDay(Employee $employee, string $date, string $in, string $out, string $status = 'on_time'): void
{
    Attendance::create([
        'employee_id' => $employee->id, 'date' => $date,
        'check_in' => Carbon::parse("{$date} {$in}"), 'check_out' => Carbon::parse("{$date} {$out}"),
        'status' => $status, 'is_late' => false, 'work_mode' => 'office',
    ]);
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 19:00'));
    $shift = ShiftSetting::create(['name' => 'Day', 'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 0, 'is_default' => true]);
    $this->dept = Department::factory()->create(['name' => 'AC Operations']);
    $this->manager = acUser('manager', $this->dept, 'Mia Manager');
    $this->hr = acUser('hr_admin', $this->dept, 'Hana HR');
    $common = ['manager_id' => $this->manager->id, 'shift_id' => $shift->id, 'joining_date' => '2026-10-12'];
    $this->ava = acUser('employee', $this->dept, 'Ava Absent', $common)->employee;
    $this->ben = acUser('employee', $this->dept, 'Ben Leave', $common)->employee;

    acDay($this->ava, '2026-10-12', '08:55', '18:00');
    acDay($this->ava, '2026-10-14', '09:40', '18:00');          // late, stored flag says on_time
    acDay($this->ben, '2026-10-12', '08:50', '18:00');
    acDay($this->ben, '2026-10-14', '08:58', '18:00');
    LeaveRequest::create([
        'employee_id' => $this->ben->id, 'leave_type_id' => LeaveType::create(['name' => 'Casual'])->id,
        'start_date' => '2026-10-13', 'end_date' => '2026-10-13', 'days' => 1, 'status' => 'approved', 'reason' => 'Appointment',
    ]);
});

test('today: Team View, AI and the window agree Ava is late and both are in', function () {
    $today = app(TodayStatusService::class)->summary([$this->ava->id, $this->ben->id]);
    expect([$today['present'], $today['late']])->toBe([2, 1]);

    Livewire::actingAs($this->manager)->test(ManagerDashboard::class)
        ->assertViewHas('presentCount', 2)->assertViewHas('lateCount', 1);

    $ai = app(AiDataTools::class)->attendanceToday($this->hr, $this->dept->id)['summary'];
    expect([$ai['present'], $ai['late']])->toBe([2, 1]);

    $days = app(AttendanceWindowService::class)->days($this->ava, Carbon::parse('2026-10-12'), Carbon::parse('2026-10-14'));
    expect($days['2026-10-14']['status'])->toBe('late')
        ->and($days['2026-10-13']['status'])->toBe('absent');
});

test('the range: Employee 360, the Monthly report and the Compliance CSV give the same counts', function () {
    $from = Carbon::parse('2026-10-12');
    $to = Carbon::parse('2026-10-14');
    $expected = [
        $this->ava->id => ['present' => 2, 'late' => 1, 'absent' => 1, 'leave' => 0.0],
        $this->ben->id => ['present' => 2, 'late' => 0, 'absent' => 0, 'leave' => 1.0],
    ];

    // Employee 360 (AttendanceWindowService).
    foreach ([$this->ava, $this->ben] as $employee) {
        $s = app(Employee360Service::class)->build($this->hr, $employee, $from, $to)['attendance']['summary'];
        expect([$s['present'], $s['late'], $s['absent'], (float) $s['leave_days']])->toBe(array_values($expected[$employee->id]));
    }

    // Attendance Reports → Monthly: absent never counts the approved leave day.
    $monthly = app(AttendanceReportBuilder::class)->build('monthly', ['from' => '2026-10-12', 'to' => '2026-10-14', 'employee_ids' => [$this->ava->id, $this->ben->id]]);
    $rows = collect($monthly['rows'])->keyBy(1);
    expect([$rows['Ava Absent'][3], $rows['Ava Absent'][4], $rows['Ava Absent'][5]])->toBe([2, 1, 1])
        ->and([$rows['Ben Leave'][3], $rows['Ben Leave'][4], $rows['Ben Leave'][5]])->toBe([2, 0, 0]);

    // Attendance Compliance CSV for October (employment starts on the 12th).
    $csv = $this->actingAs($this->hr)->get(route('reports.attendance-compliance', ['month' => 10, 'year' => 2026]))->assertOk()->streamedContent();
    $lines = collect(preg_split('/\R/', trim($csv)))->map(fn ($l) => str_getcsv($l));
    $header = $lines->first();
    $byName = $lines->slice(1)->keyBy(1)->map(fn ($row) => array_combine($header, $row));

    expect($header)->toContain('Absent (no attendance, no approved leave)')->not->toContain('Unauthorized Absence')
        ->and([(int) $byName['Ava Absent']['Present'], (int) $byName['Ava Absent']['Late'], (int) $byName['Ava Absent']['Absent (no attendance, no approved leave)']])->toBe([2, 1, 1])
        ->and([(int) $byName['Ben Leave']['Present'], (int) $byName['Ben Leave']['Absent (no attendance, no approved leave)'], (float) $byName['Ben Leave']['Leave Days']])->toBe([2, 0, 1.0]);
});
