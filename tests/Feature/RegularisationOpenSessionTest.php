<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Attendance\AttendanceDayRebuilder;
use App\Services\Attendance\RegularisationManager;
use App\Services\AttendanceService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * IN-only regularisation for a session still open today: no OUT is asked
 * for, approval writes only the IN and leaves the day open, and a later real
 * Card OUT completes it. Today = Wed 14 Oct 2026; shift 09:00–18:00, so the
 * missing-checkout cutoff is 19:00.
 */
function rosEmployee(): Employee
{
    $shift = ShiftSetting::firstOrCreate(['code' => 'ROS_DAY'], [
        'name' => 'Open Session Day', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
        'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9, 'break_duration' => 60,
    ]);

    return Employee::factory()->create(['shift_id' => $shift->id, 'status' => 'active', 'joining_date' => '2024-01-01']);
}

/** @param  array<int, array{0: string, 1: string}>  $punches */
function rosPunches(Employee $employee, string $date, array $punches): void
{
    foreach ($punches as [$time, $method]) {
        AttendancePunch::create([
            'employee_id' => $employee->id, 'punched_at' => "$date $time", 'punch_date' => $date,
            'method' => $method, 'source' => 'biometric',
        ]);
    }
    app(AttendanceDayRebuilder::class)->rebuild($employee, Carbon::parse($date));
}

function rosForm(Employee $employee, string $date)
{
    return Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->call('openRegularisation', $date)
        ->set('regReason', 'Face reader was down at the gate');
}

function rosDay(Employee $employee, string $date)
{
    return app(AttendanceCalculator::class)->forDay($employee, $date, Attendance::where('employee_id', $employee->id)->whereDate('date', $date)->first());
}

function rosApproveSole(): ?Attendance
{
    return app(AttendanceService::class)->approveRegularisation(AttendanceRegularisation::sole(), User::factory()->create(['role' => UserRole::HrAdmin])->id);
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 11:30:00'));
    $this->employee = rosEmployee();
});

test('today, still working with no IN recorded: an IN-only request is allowed with no OUT', function () {
    rosForm($this->employee, '2026-10-14')
        ->assertSet('regOpenSession', true)
        ->assertSet('regFixIn', true)
        ->assertSet('regFixOut', false)
        ->assertSee('Still working — no check-out needed yet')
        ->set('regCheckIn', '09:00')
        ->set('regCheckOut', '')
        ->call('submitRegularisation')
        ->assertHasNoErrors();

    $reg = AttendanceRegularisation::sole();
    expect($reg->corrected_side)->toBe('in')
        ->and(Carbon::parse($reg->requested_check_in)->format('H:i'))->toBe('09:00')
        ->and($reg->requested_check_out)->toBeNull()
        ->and($reg->check_out_method)->toBeNull()
        ->and($reg->requestedWindowLabel())->toBe('09:00 → open (no OUT yet)');
});

test('today, a wrong IN while still working: the form pre-ticks IN and needs only the IN', function () {
    rosPunches($this->employee, '2026-10-14', [['09:40:00', 'face']]);

    rosForm($this->employee, '2026-10-14')
        ->assertSet('regOpenSession', true)
        ->assertSet('regFixIn', true)
        ->assertSet('regFixOut', false)
        ->set('regCheckIn', '09:00')
        ->call('submitRegularisation')
        ->assertHasNoErrors();

    expect(AttendanceRegularisation::sole()->corrected_side)->toBe('in')
        ->and(AttendanceRegularisation::sole()->requested_check_out)->toBeNull();
});

test('approval writes only the IN, keeps the session open, invents no OUT and files no overtime', function () {
    rosForm($this->employee, '2026-10-14')->set('regCheckIn', '09:00')->call('submitRegularisation')->assertHasNoErrors();

    $attendance = rosApproveSole();

    $own = AttendancePunch::where('employee_id', $this->employee->id)->where('source', 'regularisation')->get();
    expect($own)->toHaveCount(1)
        ->and($own->first()->direction)->toBe('in')
        ->and($own->first()->punched_at->format('Y-m-d H:i:s'))->toBe('2026-10-14 09:00:00')
        ->and(AttendancePunch::where('employee_id', $this->employee->id)->where('direction', 'out')->exists())->toBeFalse()
        ->and($attendance->check_out)->toBeNull()
        ->and($attendance->is_auto_checkout)->toBeFalse()
        ->and($attendance->is_regularized)->toBeTrue()
        ->and(OtRequest::where('employee_id', $this->employee->id)->exists())->toBeFalse();

    $day = rosDay($this->employee, '2026-10-14');
    expect($day->live)->toBeTrue()
        ->and($day->firstIn->format('H:i'))->toBe('09:00')
        ->and($day->lastOut)->toBeNull()
        ->and($day->missingCheckout)->toBeFalse()
        ->and($day->workedMinutes)->toBe(150)          // 09:00 → 11:30, still counting
        ->and($day->isLate)->toBeFalse();
});

test('a later real Card OUT completes the day normally', function () {
    rosForm($this->employee, '2026-10-14')->set('regCheckIn', '09:00')->call('submitRegularisation')->assertHasNoErrors();
    rosApproveSole();

    $this->travelTo(Carbon::parse('2026-10-14 18:40:00'));
    rosPunches($this->employee, '2026-10-14', [['18:30:12', 'id_card']]);   // as the next sync does

    $day = rosDay($this->employee, '2026-10-14');
    expect($day->live)->toBeFalse()
        ->and($day->firstIn->format('H:i'))->toBe('09:00')
        ->and($day->lastOut->format('H:i:s'))->toBe('18:30:12')
        ->and($day->workedMinutes)->toBe(570)
        ->and($day->missingCheckout)->toBeFalse()
        ->and(Attendance::where('employee_id', $this->employee->id)->sole()->check_out->format('H:i:s'))->toBe('18:30:12')
        ->and(AttendancePunch::where('source', 'regularisation')->count())->toBe(1);
});

test('with no real OUT by the cutoff the day reads missing checkout — the correction never closes it', function () {
    rosForm($this->employee, '2026-10-14')->set('regCheckIn', '09:00')->call('submitRegularisation')->assertHasNoErrors();
    rosApproveSole();

    $this->travelTo(Carbon::parse('2026-10-14 23:00:00'));
    $day = rosDay($this->employee, '2026-10-14');

    expect($day->lastOut)->toBeNull()
        ->and($day->missingCheckout)->toBeTrue()
        ->and($day->workedMinutes)->toBe(0)
        ->and(AttendancePunch::where('direction', 'out')->exists())->toBeFalse();
});

test('a past day with no OUT still requires the OUT', function () {
    rosPunches($this->employee, '2026-10-12', [['09:05:00', 'face']]);

    rosForm($this->employee, '2026-10-12')
        ->assertSet('regOpenSession', false)
        ->set('regFixIn', true)->set('regFixOut', false)
        ->set('regCheckIn', '09:00')->set('regCheckOut', '')
        ->call('submitRegularisation')
        ->assertHasErrors(['regCheckOut'])
        ->assertSee('Please enter the missing check-out time.');

    expect(AttendanceRegularisation::count())->toBe(0);
});

test('today after the missing-checkout cutoff is no longer open, so the OUT is required', function () {
    $this->travelTo(Carbon::parse('2026-10-14 19:30:00'));
    rosPunches($this->employee, '2026-10-14', [['09:40:00', 'face']]);

    rosForm($this->employee, '2026-10-14')
        ->assertSet('regOpenSession', false)
        ->set('regFixIn', true)->set('regFixOut', false)
        ->set('regCheckIn', '09:00')->set('regCheckOut', '')
        ->call('submitRegularisation')
        ->assertHasErrors(['regCheckOut']);
});

test('HR sees the open window, and undoing the approval removes only its IN punch', function () {
    rosPunches($this->employee, '2026-10-14', [['09:40:00', 'face']]);
    rosForm($this->employee, '2026-10-14')->set('regCheckIn', '09:00')->call('submitRegularisation')->assertHasNoErrors();
    rosApproveSole();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    app(RegularisationManager::class)->delete(AttendanceRegularisation::sole(), $hr, 'Raised in error', confirmed: true);

    expect(AttendancePunch::where('source', 'regularisation')->exists())->toBeFalse()
        ->and(AttendancePunch::where('source', 'biometric')->sole()->punched_at->format('H:i'))->toBe('09:40')
        ->and(rosDay($this->employee, '2026-10-14')->firstIn->format('H:i'))->toBe('09:40');
});
