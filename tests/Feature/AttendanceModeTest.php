<?php

use App\Enums\AttendanceMode;
use App\Enums\UserRole;
use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\AttendanceSetting;
use App\Models\BiometricDevice;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Notifications\AttendanceRegularisationNotification;
use App\Services\AttendanceService;
use App\Support\UserAgent;
use Illuminate\Support\Facades\Notification;
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
        ->assertSee('Punch In / Out Timeline')
        ->assertSee('Hybrid');            // the mode appears (timeline chip / filter)
});

test('the tracker supports a weekly filter and renders the analytics charts', function () {
    $employee = Employee::factory()->create();

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSee('Working Hours Trend')
        ->assertSee('Attendance Score Trend')
        ->assertSee('Weekly Attendance')
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
        ->assertSee('Biometric Status')
        ->assertSee('Punch Source')
        ->assertSee('ESSL MB20')
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
        ->assertSet('attendanceJourney', fn ($j) => count($j) === 4
            && $j[0]['type'] === 'in' && $j[1]['type'] === 'break'
            && $j[2]['type'] === 'resume' && $j[3]['type'] === 'out'
            && $j[0]['method'] === 'face' && $j[1]['method'] === 'id_card');
});

test('smart alerts show the all-clear when the day is complete', function () {
    dayShift(); // 09:00–18:00 — a full on-time day within shift raises nothing
    $employee = Employee::factory()->create();
    Attendance::create([
        'employee_id' => $employee->id, 'date' => today(),
        'check_in' => today()->setTime(9, 0), 'check_out' => today()->setTime(18, 0),
        'status' => 'on_time', 'work_mode' => 'office', 'total_hours' => 9,
    ]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSet('attendanceAlerts', [])
        ->assertSee('No attendance issues today');
});

test('smart alerts flag a past missing check-out with a regularize action', function () {
    $this->travelTo(Carbon\Carbon::parse('2026-07-20 10:00:00'));
    $employee = Employee::factory()->create();
    Attendance::create([
        'employee_id' => $employee->id, 'date' => '2026-07-10',
        'check_in' => '2026-07-10 09:00:00', 'check_out' => null,
        'status' => 'on_time', 'work_mode' => 'office',
    ]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSee('Missing Check-Out')
        ->assertSee('Shift Progress')
        ->assertSet('attendanceAlerts', fn ($a) => collect($a)->contains(fn ($x) => $x['type'] === 'missing_checkout'));

    $this->travelBack();
});

test('the journey classifies a midday gap as a lunch break with duration', function () {
    $employee = Employee::factory()->create();
    foreach (['09:00', '13:00', '13:45', '18:00'] as $t) {
        AttendancePunch::create([
            'employee_id' => $employee->id,
            'punched_at' => today()->setTimeFromTimeString($t),
            'punch_date' => today(),
            'method' => 'face',
            'source' => 'biometric',
        ]);
    }

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSet('attendanceJourney', fn ($j) => str_starts_with($j[1]['title'], 'Lunch break') && str_contains($j[1]['title'], '45m'));
});

test('showPunchDetail includes the full punch replay and audit history', function () {
    $employee = Employee::factory()->create();
    $reviewer = User::factory()->create(['name' => 'HR REVIEWER']);
    $date = now()->startOfMonth()->addDays(5)->toDateString();

    Attendance::create([
        'employee_id' => $employee->id, 'date' => $date,
        'check_in' => $date.' 09:00:00', 'check_out' => $date.' 18:00:00',
        'status' => 'on_time', 'work_mode' => 'office', 'total_hours' => 9,
    ]);
    foreach (['09:00', '18:00'] as $t) {
        AttendancePunch::create([
            'employee_id' => $employee->id, 'punched_at' => "$date $t:00", 'punch_date' => $date,
            'method' => 'face', 'source' => 'biometric', 'device_serial' => 'AIFACE',
        ]);
    }
    AttendanceRegularisation::create([
        'employee_id' => $employee->id, 'work_date' => $date,
        'requested_check_in' => "$date 09:00:00", 'requested_check_out' => "$date 18:00:00",
        'reason' => 'Forgot to punch out', 'status' => 'approved',
        'reviewer_id' => $reviewer->id, 'reviewed_at' => now(),
    ]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->call('showPunchDetail', $date)
        ->assertSet('detail.punches', fn ($p) => count($p) === 2 && $p[0]['device'] === 'AIFACE')
        ->assertSet('detail.audits', fn ($a) => count($a) === 1 && $a[0]['status'] === 'approved' && $a[0]['reviewer'] === 'HR REVIEWER');
});

test('smart alerts flag late arrival and long break as info/action correctly', function () {
    $employee = Employee::factory()->create();
    Attendance::create([
        'employee_id' => $employee->id, 'date' => today(),
        'check_in' => today()->setTime(11, 30), 'check_out' => today()->setTime(19, 0),
        'is_late' => true, 'late_minutes' => 55, 'status' => 'late', 'work_mode' => 'office',
    ]);
    foreach (['11:30', '13:00', '15:00', '19:00'] as $t) { // 2h break = long
        AttendancePunch::create([
            'employee_id' => $employee->id, 'punched_at' => today()->setTimeFromTimeString($t),
            'punch_date' => today(), 'method' => 'face', 'source' => 'biometric',
        ]);
    }

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSet('attendanceAlerts', function ($alerts) {
            $types = collect($alerts)->pluck('type');

            return $types->contains('late_arrival') && $types->contains('long_break')
                && collect($alerts)->firstWhere('type', 'long_break')['action'] === false;
        });
});

test('attendance insights are generated from real period data', function () {
    $employee = Employee::factory()->create();
    Attendance::create([
        'employee_id' => $employee->id, 'date' => now()->startOfMonth()->addDays(1),
        'check_in' => now()->startOfMonth()->addDays(1)->setTime(9, 30),
        'check_out' => now()->startOfMonth()->addDays(1)->setTime(18, 30),
        'status' => 'on_time', 'work_mode' => 'office', 'total_hours' => 9, 'break_minutes' => 30,
    ]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSee('Attendance Insights')
        ->assertSet('insights', fn ($i) => count($i) >= 3
            && collect($i)->contains(fn ($x) => str_contains($x['text'], 'Average check-in'))
            && collect($i)->contains(fn ($x) => str_contains($x['text'], 'Break compliance')));
});

test('the journey collapses duplicate punches logged seconds apart', function () {
    $employee = Employee::factory()->create();
    foreach (['09:00:00', '09:00:40', '13:00:00', '13:45:00', '18:00:00', '18:01:10'] as $t) {
        AttendancePunch::create([
            'employee_id' => $employee->id,
            'punched_at' => today()->toDateString().' '.$t,
            'punch_date' => today(),
            'method' => 'face',
            'source' => 'biometric',
        ]);
    }

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSet('attendanceJourney', fn ($j) => count($j) === 4
            && $j[0]['type'] === 'in' && $j[3]['type'] === 'out');
});

test('a custom date range drives the stats and the attendance log', function () {
    $employee = Employee::factory()->create();
    Attendance::create([
        'employee_id' => $employee->id, 'date' => '2026-06-10',
        'check_in' => '2026-06-10 09:00:00', 'check_out' => '2026-06-10 18:00:00',
        'status' => 'on_time', 'work_mode' => 'office', 'total_hours' => 9,
    ]);
    Attendance::create([
        'employee_id' => $employee->id, 'date' => today(),
        'check_in' => today()->setTime(9, 0), 'check_out' => today()->setTime(18, 0),
        'status' => 'on_time', 'work_mode' => 'office', 'total_hours' => 9,
    ]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->set('rangeFrom', '2026-06-01')
        ->set('rangeTo', '2026-06-30')
        ->assertSet('statsPeriod', 'custom')
        ->assertSet('stats.present', 1)
        ->assertSet('history', fn ($h) => count($h) === 1 && $h[0]->date->toDateString() === '2026-06-10')
        // Switching back to a period tab restores the month log
        ->set('statsPeriod', 'this_month')
        ->assertSet('rangeFrom', null);
});

test('regularising only the check-out keeps the recorded check-in', function () {
    Notification::fake();
    $managerUser = User::factory()->create(['role' => UserRole::Manager]);
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = Employee::factory()->create(['manager_id' => $managerUser->id]);
    $date = now()->startOfMonth()->addDays(6)->toDateString();
    Attendance::create([
        'employee_id' => $employee->id, 'date' => $date,
        'check_in' => "$date 09:17:00", 'check_out' => null, 'missing_checkout' => true,
        'status' => 'on_time', 'work_mode' => 'office',
    ]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->call('openRegularisation', $date)
        ->assertSet('regFixIn', false)     // check-in exists → not pre-ticked
        ->assertSet('regFixOut', true)     // check-out missing → pre-ticked
        ->set('regCheckOut', '18:30')
        ->set('regReason', 'Forgot to punch out at the gate')
        ->call('submitRegularisation')
        ->assertHasNoErrors();

    $reg = AttendanceRegularisation::where('employee_id', $employee->id)->firstOrFail();
    expect(Illuminate\Support\Carbon::parse($reg->requested_check_in)->format('H:i'))->toBe('09:17'); // untouched
    expect(Illuminate\Support\Carbon::parse($reg->requested_check_out)->format('H:i'))->toBe('18:30');

    // Manager AND HR are both notified.
    Notification::assertSentTo($managerUser, AttendanceRegularisationNotification::class);
    Notification::assertSentTo($hr, AttendanceRegularisationNotification::class);
});

test('journey events carry the duration from the previous punch', function () {
    $employee = Employee::factory()->create();
    foreach (['09:00', '13:00', '13:37', '18:00'] as $t) {
        AttendancePunch::create([
            'employee_id' => $employee->id,
            'punched_at' => today()->setTimeFromTimeString($t),
            'punch_date' => today(),
            'method' => 'face',
            'source' => 'biometric',
        ]);
    }

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSet('attendanceJourney', fn ($j) => $j[0]['gap_min'] === null
            && $j[1]['gap_min'] === 240      // 09:00 → 13:00
            && $j[2]['gap_min'] === 37       // break duration
            && $j[3]['gap_min'] === 263);
});

test('insights include longest break, best day and total overtime', function () {
    $employee = Employee::factory()->create();
    $d = now()->startOfMonth()->addDays(2); // a Wednesday-or-whatever weekday
    Attendance::create([
        'employee_id' => $employee->id, 'date' => $d->toDateString(),
        'check_in' => $d->copy()->setTime(9, 0), 'check_out' => $d->copy()->setTime(19, 30),
        'status' => 'on_time', 'work_mode' => 'office', 'total_hours' => 10.5, 'break_minutes' => 42,
    ]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSet('insights', fn ($i) => collect($i)->contains(fn ($x) => str_contains($x['text'], 'Longest break 42 mins'))
            && collect($i)->contains(fn ($x) => str_contains($x['text'], 'Best attendance day: '.$d->format('l')))
            && collect($i)->contains(fn ($x) => str_contains($x['text'], 'Total overtime')));
});

test('the analytics grid renders all enterprise panels', function () {
    $employee = Employee::factory()->create();
    Attendance::create([
        'employee_id' => $employee->id, 'date' => now()->startOfMonth()->addDays(1),
        'check_in' => now()->startOfMonth()->addDays(1)->setTime(9, 0),
        'check_out' => now()->startOfMonth()->addDays(1)->setTime(19, 30),
        'status' => 'on_time', 'work_mode' => 'office', 'total_hours' => 10.5, 'break_minutes' => 40,
    ]);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertOk()
        ->assertSee('Attendance Analytics')
        ->assertSee('Monthly Attendance')
        ->assertSee('Late Arrival Trend')
        ->assertSee('Break Analysis')
        ->assertSee('Office vs WFH vs Hybrid')
        ->assertSee('Overtime Trend')
        ->assertSee('Productivity Score')
        ->assertSee('Attendance Heatmap');
});

test('the analytics mode filter narrows the period stats', function () {
    $employee = Employee::factory()->create();
    foreach ([['office', 1], ['wfh', 2]] as [$mode, $day]) {
        Attendance::create([
            'employee_id' => $employee->id, 'date' => now()->startOfMonth()->addDays($day),
            'check_in' => now()->startOfMonth()->addDays($day)->setTime(9, 0),
            'check_out' => now()->startOfMonth()->addDays($day)->setTime(18, 0),
            'status' => 'on_time', 'work_mode' => $mode, 'total_hours' => 9,
        ]);
    }

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSet('stats.present', 2)
        ->set('analyticsMode', 'wfh')
        ->assertSet('stats.present', 1)
        ->set('analyticsMode', '')
        ->assertSet('stats.present', 2);
});

test('AI insight stats compute averages, streak, prediction and suggestions', function () {
    $employee = Employee::factory()->create();
    foreach ([1, 2] as $day) {
        Attendance::create([
            'employee_id' => $employee->id, 'date' => now()->startOfMonth()->addDays($day),
            'check_in' => now()->startOfMonth()->addDays($day)->setTime(9, 30),
            'check_out' => now()->startOfMonth()->addDays($day)->setTime(18, 30),
            'status' => 'on_time', 'work_mode' => 'office', 'total_hours' => 9, 'break_minutes' => 30,
        ]);
    }

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSee('AI Attendance Insights')
        ->assertSee('Suggestions')
        ->assertSet('insightStats', function ($s) {
            return $s['avg_in'] === '09:30 AM'
                && $s['avg_out'] === '06:30 PM'
                && $s['streak'] >= 2
                && $s['late_count'] === 0
                && $s['prediction'] >= 0 && $s['prediction'] <= 100
                && count($s['suggestions']) >= 2;
        });
});
