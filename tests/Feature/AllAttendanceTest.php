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

    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->call('openEmployeeDrawer', $employee->id)
        ->assertSet('drawerEmployeeId', $employee->id)
        ->assertSet('drawer.name', 'DRAWER PERSON')
        ->assertSet('drawer.status', 'Completed')
        ->assertSet('drawer.punches', fn ($p) => count($p) === 1 && $p[0]['method'] === 'Face')
        ->assertSee('Attendance History')
        ->call('closeDrawer')
        ->assertSet('drawerEmployeeId', null);
});

test('the drawer trusts the engine daily summary over incomplete local punches', function () {
    // Mayuresh scenario: the engine synced 11 punches → 6h / 18m, still inside.
    // HRMS only received a partial punch stream that mis-pairs to 4h22m / 114m.
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $empUser = User::factory()->create(['name' => 'ENGINE PERSON']);
    $employee = Employee::factory()->create(['user_id' => $empUser->id, 'status' => 'active']);

    // Partial/incorrect local data (the last punch is really a live IN).
    Attendance::create([
        'employee_id' => $employee->id, 'date' => today(),
        'check_in' => today()->setTime(10, 19), 'check_out' => today()->setTime(16, 36),
        'status' => 'late', 'is_late' => true, 'work_mode' => 'office', 'break_minutes' => 114,
    ]);
    foreach (['10:19', '12:56', '13:19', '13:30', '15:02', '16:34'] as $t) {
        AttendancePunch::create([
            'employee_id' => $employee->id, 'punched_at' => today()->setTimeFromTimeString($t),
            'punch_date' => today(), 'method' => null, 'source' => 'biometric', 'device_serial' => 'TDBD25',
        ]);
    }

    // Engine's authoritative summary — the source of truth.
    AttendanceDailySummary::create([
        'employee_id' => $employee->id, 'employee_code' => $employee->employee_code ?? 16,
        'date' => today(), 'first_punch' => today()->setTime(10, 19), 'last_punch' => today()->setTime(16, 36),
        'first_punch_method' => 'id_card', 'last_punch_method' => 'face',
        'break_minutes' => 18, 'working_hours' => 6.0, 'late_minutes' => 189,
        'overtime_minutes' => 0, 'status' => 'in_office', 'device_serial' => 'TDBD25',
        'raw_punch_count' => 11, 'synced_at' => now(),
    ]);

    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->call('openEmployeeDrawer', $employee->id)
        ->assertSet('drawer.today.worked', '6h 0m')      // engine, not 4h 22m
        ->assertSet('drawer.today.break', 18)            // engine, not 114
        ->assertSet('drawer.today.out', null)            // odd punch count → still inside
        ->assertSet('drawer.status', 'Working')          // not "Completed"
        ->assertSet('drawer.punch_count', 11)            // engine count
        ->assertSet('drawer.punches_partial', true);     // only 6 of 11 held locally
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
