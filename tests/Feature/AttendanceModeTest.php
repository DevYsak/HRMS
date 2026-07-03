<?php

use App\Enums\AttendanceMode;
use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use App\Models\AttendanceSetting;
use App\Models\BiometricDevice;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\AttendanceService;
use App\Support\UserAgent;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function dayShift(): ShiftSetting
{
    return ShiftSetting::create([
        'name' => 'Day',
        'start_time' => '09:00',
        'end_time' => '18:00',
        'grace_minutes' => 5,
        'break_duration' => 60,
        'standard_hours' => 9,
    ]);
}

test('the attendance service persists every supported work mode', function () {
    $shift = dayShift();

    foreach (AttendanceMode::values() as $mode) {
        $employee = Employee::factory()->create();
        $attendance = app(AttendanceService::class)->checkIn($employee, $shift, ['work_mode' => $mode]);
        expect($attendance->work_mode)->toBe($mode);
    }
});

test('an unknown work mode falls back to office', function () {
    $employee = Employee::factory()->create();

    $attendance = app(AttendanceService::class)->checkIn($employee, dayShift(), ['work_mode' => 'mars']);

    expect($attendance->work_mode)->toBe('office');
});

test('the attendance service records the punch user agent', function () {
    $employee = Employee::factory()->create();

    $attendance = app(AttendanceService::class)->checkIn($employee, dayShift(), [
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36',
    ]);

    expect($attendance->check_in_user_agent)->toContain('Chrome');
    expect(UserAgent::parse($attendance->check_in_user_agent)['browser'])->toBe('Chrome');
});

test('clocking in stores a punch selfie and geolocation', function () {
    Storage::fake('public');
    dayShift();
    $user = User::factory()->create();
    $employee = Employee::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test(AttendanceTracker::class)
        ->call('checkIn', 12.9716, 77.5946, 'data:image/jpeg;base64,/9j/4AAQSkZJRg==');

    $att = Attendance::where('employee_id', $employee->id)->firstOrFail();

    expect((float) $att->check_in_lat)->toBe(12.9716);
    expect((float) $att->check_in_lng)->toBe(77.5946);
    expect($att->check_in_photo)->not->toBeNull();
    Storage::disk('public')->assertExists($att->check_in_photo);
});

test('clocking in without a photo or location still works', function () {
    Storage::fake('public');
    dayShift();
    $user = User::factory()->create();
    $employee = Employee::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test(AttendanceTracker::class)->call('checkIn');

    $att = Attendance::where('employee_id', $employee->id)->firstOrFail();

    expect($att->check_in_photo)->toBeNull();
    expect($att->check_in_lat)->toBeNull();
});

test('a non-image payload is rejected and no file is written', function () {
    Storage::fake('public');
    dayShift();
    $user = User::factory()->create();
    $employee = Employee::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    Livewire::test(AttendanceTracker::class)
        ->call('checkIn', null, null, 'data:text/html;base64,PHNjcmlwdD4=');

    $att = Attendance::where('employee_id', $employee->id)->firstOrFail();

    expect($att->check_in_photo)->toBeNull();
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('clocking in from the tracker records the selected mode', function () {
    $shift = dayShift();
    $employee = Employee::factory()->create(['shift_id' => $shift->id]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertOk()
        ->set('workMode', 'client_visit')
        ->call('checkIn');

    expect(Attendance::where('employee_id', $employee->id)->value('work_mode'))->toBe('client_visit');
});

test('the tracker shows the multi-mode breakdown and legend', function () {
    $employee = Employee::factory()->create();
    $d = now()->startOfMonth()->addDays(3);
    Attendance::create([
        'employee_id' => $employee->id,
        'date' => $d->toDateString(),
        'check_in' => $d->copy()->setTime(10, 0),
        'check_out' => $d->copy()->setTime(18, 0),
        'status' => 'on_time',
        'work_mode' => 'hybrid',
        'total_hours' => 8,
    ]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertOk()
        ->assertSee('Hybrid Summary')     // Office · WFH · Hybrid Summary panel
        ->assertSee('Hybrid');            // the logged mode is listed
});

test('the tracker supports a weekly filter and renders the analytics charts', function () {
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSee('Working Hours Trend')
        ->assertSee('Attendance Heatmap')
        ->assertSee('Late Arrival Trend')
        ->set('statsPeriod', 'this_week')
        ->assertOk()
        ->assertSet('statsPeriod', 'this_week');
});

test('showPunchDetail loads the full punch detail for a day', function () {
    $employee = Employee::factory()->create();
    Attendance::create([
        'employee_id' => $employee->id,
        'date' => now()->startOfMonth()->addDays(2)->toDateString(),
        'check_in' => now()->startOfMonth()->addDays(2)->setTime(9, 30),
        'check_out' => now()->startOfMonth()->addDays(2)->setTime(18, 30),
        'check_in_method' => 'face',
        'check_out_method' => 'id_card',
        'total_hours' => 9,
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->call('showPunchDetail', now()->startOfMonth()->addDays(2)->toDateString())
        ->assertSet('detail.in.method', 'Face')
        ->assertSet('detail.out.method', 'ID Card');
});

test('showPunchDetail ignores a date belonging to another employee', function () {
    $me = Employee::factory()->create();
    $other = Employee::factory()->create();
    $date = now()->startOfMonth()->addDays(3)->toDateString();
    Attendance::create([
        'employee_id' => $other->id,
        'date' => $date,
        'check_in' => now()->startOfMonth()->addDays(3)->setTime(9, 0),
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);

    Livewire::actingAs($me->user)->test(AttendanceTracker::class)
        ->call('showPunchDetail', $date)
        ->assertSet('detail', null);
});

test('the attendance log mode filter is a live property', function () {
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSet('logMode', '')
        ->set('logMode', 'wfh')
        ->assertOk()
        ->assertSet('logMode', 'wfh');
});

test('requires_location blocks clock-in without coordinates', function () {
    dayShift();
    AttendanceSetting::create(['shift_start' => '09:00', 'shift_end' => '18:00', 'requires_location' => true]);
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)->call('checkIn');

    expect(Attendance::where('employee_id', $employee->id)->count())->toBe(0);
});

test('requires_photo blocks clock-in without a selfie', function () {
    dayShift();
    AttendanceSetting::create(['shift_start' => '09:00', 'shift_end' => '18:00', 'requires_photo' => true]);
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)->call('checkIn', 12.9, 77.5, null);

    expect(Attendance::where('employee_id', $employee->id)->count())->toBe(0);
});

test('clock-in succeeds when required location and photo are supplied', function () {
    Storage::fake('public');
    dayShift();
    AttendanceSetting::create(['shift_start' => '09:00', 'shift_end' => '18:00', 'requires_location' => true, 'requires_photo' => true]);
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->call('checkIn', 12.9, 77.5, 'data:image/jpeg;base64,/9j/4AAQSkZJRg==');

    expect(Attendance::where('employee_id', $employee->id)->count())->toBe(1);
});

test('the attendance log shows both check-in and check-out methods when they differ', function () {
    $employee = Employee::factory()->create();
    Attendance::create([
        'employee_id' => $employee->id,
        'date' => now()->startOfMonth()->addDays(4)->toDateString(),
        'check_in' => now()->startOfMonth()->addDays(4)->setTime(9, 0),
        'check_out' => now()->startOfMonth()->addDays(4)->setTime(18, 0),
        'check_in_method' => 'face',
        'check_out_method' => 'id_card',
        'status' => 'on_time',
        'work_mode' => 'office',
    ]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertOk()
        ->assertSee('Face')
        ->assertSee('ID Card');
});

test('the punch summary and biometric status show the biometric daily figures', function () {
    $employee = Employee::factory()->create();
    BiometricDevice::create(['name' => 'ESSL MB20', 'ip_address' => '10.0.0.9', 'port' => 4370, 'timeout_seconds' => 5, 'last_synced_at' => now()]);
    AttendanceDailySummary::create([
        'employee_id' => $employee->id, 'employee_code' => 777, 'date' => today(),
        'first_punch' => now()->setTime(9, 2), 'last_punch' => now()->setTime(18, 15),
        'first_punch_method' => 'face', 'last_punch_method' => 'id_card',
        'break_minutes' => 32, 'working_hours' => 7.3, 'late_minutes' => 0, 'overtime_minutes' => 18,
        'status' => 'present', 'raw_punch_count' => 4, 'synced_at' => now(),
    ]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertOk()
        ->assertSee('Punch Summary')
        ->assertSee('Total Punches')
        ->assertSee('ESSL MB20')
        ->assertSee('Biometric Status')
        ->assertSee('Face + ID Card');
});

test('the attendance journey classifies every punch in sequence', function () {
    $employee = Employee::factory()->create();
    foreach ([['09:00', 'face'], ['13:00', 'id_card'], ['13:40', 'id_card'], ['18:00', 'face']] as [$t, $m]) {
        AttendancePunch::create([
            'employee_id' => $employee->id,
            'punched_at' => today()->setTimeFromTimeString($t),
            'punch_date' => today(),
            'method' => $m,
            'source' => 'biometric',
        ]);
    }

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertOk()
        ->assertSee('Attendance Journey')
        ->assertSee('4 punches')
        ->assertSet('attendanceJourney', fn ($j) => count($j) === 4
            && $j[0]['type'] === 'in' && $j[1]['type'] === 'break'
            && $j[2]['type'] === 'resume' && $j[3]['type'] === 'out'
            && $j[0]['method'] === 'face' && $j[1]['method'] === 'id_card');
});
