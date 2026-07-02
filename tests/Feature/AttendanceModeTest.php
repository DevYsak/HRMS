<?php

use App\Enums\AttendanceMode;
use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Attendance;
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
