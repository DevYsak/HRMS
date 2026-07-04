<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\CommandCenter;
use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\User;
use App\Models\WfhRequest;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

function ccHr(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

test('a regular employee cannot open the command center', function () {
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(CommandCenter::class)
        ->assertForbidden();
});

test('the command center shows pending counts per request type', function () {
    Notification::fake();
    $hr = ccHr();
    $employee = Employee::factory()->create(['status' => 'active']);
    $date = today()->subDay()->toDateString();

    AttendanceRegularisation::create([
        'employee_id' => $employee->id, 'work_date' => $date,
        'requested_check_in' => "$date 09:00:00", 'requested_check_out' => "$date 18:00:00",
        'reason' => 'Missed punch', 'status' => 'pending',
    ]);
    WfhRequest::create([
        'employee_id' => $employee->id, 'start_date' => today()->addDay(), 'end_date' => today()->addDay(),
        'reason' => 'Internet installation at home', 'status' => 'pending',
    ]);
    OtRequest::create([
        'employee_id' => $employee->id, 'work_date' => $date, 'start_time' => '18:00', 'end_time' => '20:00',
        'requested_hours' => 2, 'reason' => 'Release night', 'status' => 'pending',
    ]);

    Livewire::actingAs($hr)->test(CommandCenter::class)
        ->assertOk()
        ->assertSee('Attendance Command Center')
        ->assertViewHas('counts', fn ($c) => $c['regularisation'] === 1 && $c['wfh'] === 1 && $c['overtime'] === 1)
        ->assertSee('Activity Feed');
});

test('quick approve routes a regularisation through the real service', function () {
    Notification::fake();
    $hr = ccHr();
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

    Livewire::actingAs($hr)->test(CommandCenter::class)
        ->call('approveOne', 'regularisation', $reg->id);

    expect($reg->fresh()->status)->toBe('approved');
    expect($att->fresh()->check_out?->format('H:i'))->toBe('18:00');
});

test('bulk approve handles every selected overtime request', function () {
    Notification::fake();
    $hr = ccHr();
    $employee = Employee::factory()->create(['status' => 'active']);
    $ids = [];
    foreach ([3, 4] as $back) {
        $d = today()->subDays($back)->toDateString();
        $ids[] = OtRequest::create([
            'employee_id' => $employee->id, 'work_date' => $d, 'start_time' => '18:00', 'end_time' => '20:00',
            'requested_hours' => 2, 'reason' => 'Deployment support', 'status' => 'pending',
        ])->id;
    }

    Livewire::actingAs($hr)->test(CommandCenter::class)
        ->set('tab', 'overtime')
        ->set('selected', $ids)
        ->call('bulkApprove')
        ->assertSet('selected', []);

    expect(OtRequest::whereIn('id', $ids)->where('status', 'approved')->count())->toBe(2);
    expect(OvertimeRecord::count())->toBe(2); // approval materialises records
});

test('rejects are blocked without a comment and applied with one', function () {
    Notification::fake();
    $hr = ccHr();
    $employee = Employee::factory()->create(['status' => 'active']);
    $wfh = WfhRequest::create([
        'employee_id' => $employee->id, 'start_date' => today()->addDays(2), 'end_date' => today()->addDays(2),
        'reason' => 'Plumber visit', 'status' => 'pending',
    ]);

    $component = Livewire::actingAs($hr)->test(CommandCenter::class)->set('tab', 'wfh');

    $component->call('rejectOne', 'wfh', $wfh->id);
    expect($wfh->fresh()->status)->toBe('pending'); // blocked — no comment

    $component->set('rejectComment', 'Office presence required that day')
        ->call('rejectOne', 'wfh', $wfh->id);
    expect($wfh->fresh()->status)->toBe('rejected');
});
