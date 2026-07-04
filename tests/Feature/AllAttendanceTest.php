<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AllAttendance;
use App\Models\Attendance;
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

test('quick approve from the drawer updates the attendance and clears the pending item', function () {
    Notification::fake();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
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
        'reason' => 'Forgot to punch out', 'status' => 'pending',
    ]);

    Livewire::actingAs($hr)->test(AllAttendance::class)
        ->call('openEmployeeDrawer', $employee->id)
        ->assertSet('drawer.pending', fn ($p) => count($p) === 1)
        ->call('quickApproveRegularisation', $reg->id)
        ->assertSet('drawer.pending', []);

    expect($reg->fresh()->status)->toBe('approved');
    expect($att->fresh()->check_out?->format('H:i'))->toBe('18:00');
    expect($att->fresh()->missing_checkout)->toBeFalse();
});
