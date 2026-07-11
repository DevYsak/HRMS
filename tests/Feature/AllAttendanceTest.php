<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AllAttendance;
use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('the all-attendance board renders KPIs, trend and the log for HR', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $empUser = User::factory()->create(['name' => 'BOARDPERSON']);
    $employee = Employee::factory()->create(['user_id' => $empUser->id, 'status' => 'active']);
    Attendance::create([
        'employee_id' => $employee->id,
        'date' => today(),
        'check_in' => today()->setTime(9, 5),
        'check_out' => today()->setTime(18, 10),
        'check_in_method' => 'face',
        'status' => 'on_time',
        'work_mode' => 'office',
        'total_hours' => 8.6,
    ]);

    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->assertOk()
        ->assertSee('Employees Attendance')
        ->assertSee('Present Today')
        ->assertSee('Presence Overview')
        ->assertSee('BOARDPERSON')
        ->assertSee('Face');
});

test('the day filter narrows the log and reset clears it', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = Employee::factory()->create(['status' => 'active']);
    Attendance::create([
        'employee_id' => $employee->id, 'date' => today(),
        'check_in' => today()->setTime(9, 0), 'status' => 'on_time', 'work_mode' => 'office',
    ]);
    Attendance::create([
        'employee_id' => $employee->id, 'date' => today()->subDay(),
        'check_in' => today()->subDay()->setTime(9, 0), 'status' => 'on_time', 'work_mode' => 'office',
    ]);

    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->set('date', today()->toDateString())
        ->assertViewHas('attendances', fn ($p) => $p->total() === 1)
        ->set('date', '')
        ->assertViewHas('attendances', fn ($p) => $p->total() >= 1);
});

test('the search filter matches the employee name', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $target = Employee::factory()->create(['user_id' => User::factory()->create(['name' => 'FINDME PERSON'])->id, 'status' => 'active']);
    $other = Employee::factory()->create(['user_id' => User::factory()->create(['name' => 'SOMEONE ELSE'])->id, 'status' => 'active']);
    foreach ([$target, $other] as $e) {
        Attendance::create([
            'employee_id' => $e->id, 'date' => today(),
            'check_in' => today()->setTime(9, 0), 'status' => 'on_time', 'work_mode' => 'office',
        ]);
    }

    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->set('search', 'FINDME')
        ->assertViewHas('attendances', fn ($p) => $p->total() === 1)
        ->assertSee('FINDME PERSON');
});

test('a regular employee cannot open the all-attendance board', function () {
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(AllAttendance::class)
        ->assertForbidden();
});

test('HR can open the Employee 360 drawer with full profile data', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $empUser = User::factory()->create(['name' => 'DRAWER PERSON']);
    $employee = Employee::factory()->create(['user_id' => $empUser->id, 'status' => 'active']);
    Attendance::create([
        'employee_id' => $employee->id, 'date' => today(),
        'check_in' => today()->setTime(9, 5), 'check_out' => today()->setTime(18, 0),
        'status' => 'on_time', 'work_mode' => 'office', 'total_hours' => 8.5, 'break_minutes' => 25,
    ]);
    AttendancePunch::create([
        'employee_id' => $employee->id, 'punched_at' => today()->setTime(9, 5),
        'punch_date' => today(), 'method' => 'face', 'source' => 'biometric', 'device_serial' => 'MB20',
    ]);
    AttendancePunch::create([
        'employee_id' => $employee->id, 'punched_at' => today()->setTime(18, 0),
        'punch_date' => today(), 'method' => 'id_card', 'source' => 'biometric', 'device_serial' => 'MB20',
    ]);

    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->call('openEmployeeDrawer', $employee->id)
        ->assertSet('drawerEmployeeId', $employee->id)
        ->assertSet('drawer.name', 'DRAWER PERSON')
        ->assertSet('drawer.status', 'Completed')
        ->assertSet('drawer.punches', fn ($p) => count($p) === 2 && $p[0]['method'] === 'Face' && $p[1]['method'] === 'ID Card')
        ->assertSee('Attendance History')
        ->call('closeDrawer')
        ->assertSet('drawerEmployeeId', null);
});

test('the drawer computes worked/break from validated sessions, not the wrong engine summary', function () {
    // EMP005 scenario: the engine's summary mis-pairs this device (Face tagged
    // OUT), reporting 21m worked / 191m break. The validated Face=IN / Card=OUT
    // sessions are the truth: 10:27→13:24 and 13:44→13:57 = 3h10m, 20m break.
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $empUser = User::factory()->create(['name' => 'SESSION PERSON']);
    $employee = Employee::factory()->create(['user_id' => $empUser->id, 'status' => 'active']);

    foreach ([['10:27:00', 'face'], ['13:24:00', 'id_card'], ['13:44:00', 'face'], ['13:57:00', 'id_card']] as [$t, $method]) {
        AttendancePunch::create([
            'employee_id' => $employee->id, 'punched_at' => today()->setTimeFromTimeString($t),
            'punch_date' => today(), 'method' => $method, 'source' => 'biometric', 'device_serial' => 'TDBD25',
        ]);
    }

    // The engine's WRONG summary — must be ignored for the totals.
    AttendanceDailySummary::create([
        'employee_id' => $employee->id, 'employee_code' => $employee->employee_code ?? 5,
        'date' => today(), 'first_punch' => today()->setTime(10, 27), 'last_punch' => today()->setTime(13, 57),
        'break_minutes' => 191, 'working_hours' => 0.35, 'overtime_minutes' => 0,
        'status' => 'present', 'device_serial' => 'TDBD25', 'raw_punch_count' => 4, 'synced_at' => now(),
    ]);

    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->call('openEmployeeDrawer', $employee->id)
        ->assertSet('drawer.today.worked', '3h 10m')     // sessions, not the summary's 0h 21m
        ->assertSet('drawer.today.break', 20)            // real gap, not 191
        ->assertSet('drawer.today.out', '01:57 PM')      // last Card OUT
        ->assertSet('drawer.status', 'Completed');
});

test('the drawer lists every synced punch, including near-adjacent ones', function () {
    // 16:36 is a real session-boundary IN two minutes after a 16:34 OUT — the
    // old noise dedup dropped it from the list; the engine keeps it, so we must.
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = Employee::factory()->create(['status' => 'active']);
    foreach (['09:00', '16:34', '16:36'] as $t) {
        AttendancePunch::create([
            'employee_id' => $employee->id, 'punched_at' => today()->setTimeFromTimeString($t),
            'punch_date' => today(), 'method' => null, 'source' => 'biometric', 'device_serial' => 'TDBD25',
        ]);
    }
    AttendanceDailySummary::create([
        'employee_id' => $employee->id, 'employee_code' => $employee->employee_code ?? 16,
        'date' => today(), 'first_punch' => today()->setTime(9, 0), 'last_punch' => today()->setTime(16, 36),
        'break_minutes' => 5, 'working_hours' => 7.5, 'raw_punch_count' => 3, 'status' => 'in_office',
        'synced_at' => now(),
    ]);

    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->call('openEmployeeDrawer', $employee->id)
        ->assertSet('drawer.punches', fn ($p) => count($p) === 3
            && collect($p)->pluck('time')->contains('04:36 PM'))
        ->assertSet('drawer.punches_partial', false);    // 3 local == 3 engine
});

test('quick approve walks the stage chain; the super admin finalises and updates attendance', function () {
    Notification::fake();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $employee = Employee::factory()->create(['status' => 'active']);
    $date = today()->subDays(2)->toDateString();
    $att = Attendance::create([
        'employee_id' => $employee->id, 'date' => $date,
        'check_in' => "$date 09:00:00", 'check_out' => null, 'missing_checkout' => true,
        'status' => 'on_time', 'work_mode' => 'office',
    ]);
    $reg = AttendanceRegularisation::create([
        'employee_id' => $employee->id, 'attendance_id' => $att->id, 'work_date' => $date,
        'requested_check_in' => "$date 09:00:00", 'requested_check_out' => "$date 18:00:00",
        'reason' => 'Forgot to punch out', 'status' => 'pending', 'stage' => 'manager_review',
    ]);

    // HR quick-approve clears manager + HR review but does NOT touch attendance.
    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->call('openEmployeeDrawer', $employee->id)
        ->assertSet('drawer.pending', fn ($p) => count($p) === 1)
        ->call('quickApproveRegularisation', $reg->id)
        ->assertSet('drawer.pending', fn ($p) => count($p) === 1 && $p[0]['stage'] === 'Admin Approval');

    expect($reg->fresh()->status)->toBe('pending');
    expect($att->fresh()->check_out)->toBeNull();

    // The super admin's approval finalises: attendance updated, pending cleared.
    Livewire::actingAs($admin)->test(AllAttendance::class)
        ->call('openEmployeeDrawer', $employee->id)
        ->call('quickApproveRegularisation', $reg->id)
        ->assertSet('drawer.pending', []);

    expect($reg->fresh()->status)->toBe('approved');
    expect($att->fresh()->check_out?->format('H:i'))->toBe('18:00');
    expect($att->fresh()->missing_checkout)->toBeFalse();
});

test('the sidebar exposes the HR attendance tools with a pending badge', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = Employee::factory()->create(['status' => 'active']);
    $date = today()->subDays(2)->toDateString();
    AttendanceRegularisation::create([
        'employee_id' => $employee->id, 'work_date' => $date,
        'requested_check_in' => "$date 09:00:00", 'requested_check_out' => "$date 18:00:00",
        'reason' => 'Missed punch', 'status' => 'pending',
    ]);

    $this->actingAs($hr)
        ->get(route('attendance.employees'))
        ->assertOk()
        ->assertSee('Command Center')
        ->assertSee('Attendance Reports')
        ->assertSee('Executive View')
        ->assertSee('Biometric Control');
});

test('a pure employee does not see the HR attendance tools in the sidebar', function () {
    $employee = Employee::factory()->create();

    $this->actingAs($employee->user)
        ->get(route('attendance.my'))
        ->assertOk()
        ->assertDontSee('Command Center')
        ->assertDontSee('Attendance Reports');
});
