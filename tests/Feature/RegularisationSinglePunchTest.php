<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Attendance\AttendanceDayRebuilder;
use App\Services\Attendance\RegularisationManager;
use App\Services\AttendanceService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Single-punch regularisation: only the missing (or wrong) side is required
 * and changed; the other side keeps the day's existing canonical punch.
 * Today = Wed 14 Oct 2026; shift 09:00–18:00, 5 min grace.
 */
function srpEmployee(): Employee
{
    $shift = ShiftSetting::firstOrCreate(['code' => 'SP_DAY'], [
        'name' => 'Single Punch Day', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
        'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9, 'break_duration' => 60,
    ]);

    return Employee::factory()->create(['shift_id' => $shift->id, 'status' => 'active', 'joining_date' => '2024-01-01']);
}

/** @param  array<int, array{0: string, 1: string}>  $punches  [H:i:s, method] */
function srpPunches(Employee $employee, string $date, array $punches): void
{
    foreach ($punches as [$time, $method]) {
        AttendancePunch::create([
            'employee_id' => $employee->id, 'punched_at' => "$date $time", 'punch_date' => $date,
            'method' => $method, 'source' => 'biometric',
        ]);
    }
    app(AttendanceDayRebuilder::class)->rebuild($employee, Carbon::parse($date));
}

function srpForm(Employee $employee, string $date)
{
    return Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->call('openRegularisation', $date)
        ->set('regReason', 'Forgot to punch at the gate');
}

function srpApprove(AttendanceRegularisation $regularisation): ?Attendance
{
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    return app(AttendanceService::class)->approveRegularisation($regularisation, $hr->id);
}

function srpDay(Employee $employee, string $date)
{
    return app(AttendanceCalculator::class)->forDay($employee, $date, Attendance::where('employee_id', $employee->id)->whereDate('date', $date)->first());
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
    $this->employee = srpEmployee();
});

test('missing OUT only: the form pre-ticks OUT, needs only the check-out, and submits', function () {
    srpPunches($this->employee, '2026-10-12', [['09:05:23', 'face']]);

    srpForm($this->employee, '2026-10-12')
        ->assertSet('regFixIn', false)
        ->assertSet('regFixOut', true)
        ->assertSet('regExisting', ['in' => '09:05', 'out' => null])
        ->assertSee('Recorded: 09:05 AM')
        ->assertSee('No check-out recorded')
        ->set('regCheckIn', '')                 // the kept IN is not needed from the form
        ->set('regCheckOut', '18:00')
        ->call('submitRegularisation')
        ->assertHasNoErrors();

    $reg = AttendanceRegularisation::sole();
    expect($reg->corrected_side)->toBe('out')
        ->and(Carbon::parse($reg->requested_check_in)->format('H:i'))->toBe('09:05')
        ->and(Carbon::parse($reg->requested_check_out)->format('H:i'))->toBe('18:00')
        ->and($reg->check_in_method)->toBeNull()
        ->and($reg->check_out_method)->toBe('id_card');
});

test('missing IN only: a lone genuine Card OUT is kept and only the check-in is needed', function () {
    srpPunches($this->employee, '2026-10-13', [['18:10:42', 'id_card']]);

    srpForm($this->employee, '2026-10-13')
        ->assertSet('regFixIn', true)
        ->assertSet('regFixOut', false)
        ->assertSet('regExisting', ['in' => null, 'out' => '18:10'])
        ->set('regCheckIn', '09:00')
        ->set('regCheckOut', '')
        ->call('submitRegularisation')
        ->assertHasNoErrors();

    $reg = AttendanceRegularisation::sole();
    expect($reg->corrected_side)->toBe('in')
        ->and(Carbon::parse($reg->requested_check_in)->format('H:i'))->toBe('09:00')
        ->and(Carbon::parse($reg->requested_check_out)->format('H:i'))->toBe('18:10')
        ->and($reg->check_out_method)->toBeNull();
});

test('both missing requires both times, with specific messages', function () {
    // 09 Oct: a working day with no punches at all.
    srpForm($this->employee, '2026-10-09')
        ->assertSet('regFixIn', true)
        ->assertSet('regFixOut', true)
        ->set('regCheckIn', '')->set('regCheckOut', '')
        ->call('submitRegularisation')
        ->assertHasErrors(['regCheckIn', 'regCheckOut'])
        ->assertSee('Please enter both corrected times.')
        ->assertDontSee('Both times are needed')
        ->set('regCheckIn', '09:00')
        ->call('submitRegularisation')
        ->assertHasErrors(['regCheckOut'])
        ->assertSee('Please enter the missing check-out time.')
        ->set('regCheckIn', '')->set('regCheckOut', '18:00')
        ->call('submitRegularisation')
        ->assertHasErrors(['regCheckIn'])
        ->assertSee('Please enter the missing check-in time.')
        ->set('regCheckIn', '09:00')
        ->call('submitRegularisation')
        ->assertHasNoErrors();

    expect(AttendanceRegularisation::sole()->corrected_side)->toBe('both');
});

test('an unticked side with no recorded punch is still required', function () {
    srpPunches($this->employee, '2026-10-12', [['09:05:00', 'face']]);

    // The employee unticks OUT, but there is no OUT to keep.
    srpForm($this->employee, '2026-10-12')
        ->set('regFixIn', true)->set('regFixOut', false)
        ->set('regCheckIn', '09:00')->set('regCheckOut', '')
        ->call('submitRegularisation')
        ->assertHasErrors(['regCheckOut'])
        ->assertSee('Please enter the missing check-out time.');

    expect(AttendanceRegularisation::count())->toBe(0);
});

test('approving a missing-OUT request keeps the genuine IN and recalculates the day', function () {
    srpPunches($this->employee, '2026-10-12', [['09:05:23', 'face']]);
    srpForm($this->employee, '2026-10-12')->set('regCheckOut', '18:00')->call('submitRegularisation')->assertHasNoErrors();

    $attendance = srpApprove(AttendanceRegularisation::sole());

    // Only the corrected OUT was written as a regularisation punch; the genuine Face IN is untouched.
    $own = AttendancePunch::where('employee_id', $this->employee->id)->where('source', 'regularisation')->get();
    expect($own)->toHaveCount(1)
        ->and($own->first()->direction)->toBe('out')
        ->and($own->first()->punched_at->format('H:i:s'))->toBe('18:00:00')
        ->and(AttendancePunch::where('employee_id', $this->employee->id)->where('source', 'biometric')->sole()->punched_at->format('H:i:s'))->toBe('09:05:23');

    $day = srpDay($this->employee, '2026-10-12');
    expect($day->firstIn->format('H:i:s'))->toBe('09:05:23')
        ->and($day->lastOut->format('H:i'))->toBe('18:00')
        ->and($day->workedMinutes)->toBe(534)                // 18:00 − 09:05:23, whole minutes
        ->and($day->isLate)->toBeFalse()
        ->and($day->missingCheckout)->toBeFalse()
        ->and($attendance->is_regularized)->toBeTrue()
        ->and($attendance->check_in->format('H:i:s'))->toBe('09:05:23')
        ->and($attendance->check_in_method)->toBe('face');   // the kept punch keeps its method
});

test('approving a missing-IN request keeps the genuine Card OUT and recalculates the day', function () {
    srpPunches($this->employee, '2026-10-13', [['18:10:42', 'id_card']]);
    srpForm($this->employee, '2026-10-13')->set('regCheckIn', '09:00')->call('submitRegularisation')->assertHasNoErrors();

    $attendance = srpApprove(AttendanceRegularisation::sole());

    $own = AttendancePunch::where('employee_id', $this->employee->id)->where('source', 'regularisation')->get();
    expect($own)->toHaveCount(1)
        ->and($own->first()->direction)->toBe('in')
        ->and($own->first()->punched_at->format('H:i:s'))->toBe('09:00:00');

    $day = srpDay($this->employee, '2026-10-13');
    expect($day->firstIn->format('H:i'))->toBe('09:00')
        ->and($day->lastOut->format('H:i:s'))->toBe('18:10:42')   // the genuine punch, seconds intact
        ->and($day->workedMinutes)->toBe(550)
        ->and($attendance->check_out->format('H:i:s'))->toBe('18:10:42');
});

test('wrong IN only: the corrected IN replaces it and the genuine OUT stays', function () {
    srpPunches($this->employee, '2026-10-12', [['09:40:00', 'face'], ['18:05:11', 'id_card']]);

    srpForm($this->employee, '2026-10-12')
        ->assertSet('regExisting', ['in' => '09:40', 'out' => '18:05'])
        ->set('regFixIn', true)->set('regFixOut', false)
        ->set('regCheckIn', '09:00')->set('regCheckOut', '')
        ->call('submitRegularisation')->assertHasNoErrors();
    expect(AttendanceRegularisation::sole()->corrected_side)->toBe('in');

    srpApprove(AttendanceRegularisation::sole());

    $day = srpDay($this->employee, '2026-10-12');
    expect($day->firstIn->format('H:i'))->toBe('09:00')
        ->and($day->lastOut->format('H:i:s'))->toBe('18:05:11')
        ->and($day->isLate)->toBeFalse()
        ->and(AttendancePunch::where('source', 'regularisation')->where('direction', 'out')->exists())->toBeFalse();
});

test('wrong OUT only: the corrected OUT replaces it and the genuine IN stays', function () {
    srpPunches($this->employee, '2026-10-12', [['09:02:07', 'face'], ['13:00:00', 'id_card']]);

    srpForm($this->employee, '2026-10-12')
        ->assertSet('regFixIn', false)->assertSet('regFixOut', true)   // both exist: correcting the OUT
        ->set('regCheckOut', '18:00')
        ->call('submitRegularisation')->assertHasNoErrors();
    expect(AttendanceRegularisation::sole()->corrected_side)->toBe('out');

    srpApprove(AttendanceRegularisation::sole());

    $day = srpDay($this->employee, '2026-10-12');
    expect($day->firstIn->format('H:i:s'))->toBe('09:02:07')
        ->and($day->lastOut->format('H:i'))->toBe('18:00')
        ->and(AttendancePunch::where('source', 'regularisation')->where('direction', 'in')->exists())->toBeFalse();
});

test('the audit trail keeps the original values and records the corrected side', function () {
    srpPunches($this->employee, '2026-10-12', [['09:05:23', 'face']]);
    srpForm($this->employee, '2026-10-12')->set('regCheckOut', '18:00')->call('submitRegularisation')->assertHasNoErrors();
    $reg = AttendanceRegularisation::sole();

    $attendance = srpApprove($reg);

    expect($attendance->original_check_in->format('H:i:s'))->toBe('09:05:23')
        ->and($attendance->original_check_out)->toBeNull();

    $log = AuditLog::where('event', 'ATTENDANCE_REGULARISATION_APPROVED')->where('auditable_id', $reg->id)->sole();
    expect($log->old_values['attendance']['check_in'])->toBe('2026-10-12 09:05')
        ->and($log->old_values['attendance']['check_out'])->toBeNull()
        ->and($log->new_values['attendance']['check_out'])->toBe('2026-10-12 18:00')
        ->and($log->new_values['corrected_side'])->toBe('out');
});

test('a request made before single-side corrections still applies both times', function () {
    srpPunches($this->employee, '2026-10-12', [['09:05:00', 'face']]);
    $reg = AttendanceRegularisation::create([
        'employee_id' => $this->employee->id, 'work_date' => '2026-10-12', 'regularisation_type' => 'punch',
        'requested_check_in' => '2026-10-12 08:55:00', 'requested_check_out' => '2026-10-12 18:00:00',
        'check_in_method' => 'face', 'check_out_method' => 'id_card',
        'reason' => 'Legacy two-sided request', 'status' => 'pending', 'stage' => 'hr_review',
    ]);

    srpApprove($reg);

    expect(AttendancePunch::where('source', 'regularisation')->count())->toBe(2)
        ->and(srpDay($this->employee, '2026-10-12')->firstIn->format('H:i'))->toBe('08:55');
});

test('HR editing the kept side of a single-side request makes it a two-sided correction', function () {
    srpPunches($this->employee, '2026-10-12', [['09:05:00', 'face']]);
    srpForm($this->employee, '2026-10-12')->set('regCheckOut', '18:00')->call('submitRegularisation')->assertHasNoErrors();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    app(RegularisationManager::class)
        ->update(AttendanceRegularisation::sole(), $hr, ['check_in' => '08:50', 'check_out' => '18:00'], 'Gate log shows 08:50');

    expect(AttendanceRegularisation::sole()->corrected_side)->toBe('both');
});
