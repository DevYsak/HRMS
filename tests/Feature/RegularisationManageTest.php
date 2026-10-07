<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AllAttendance;
use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Attendance;
use App\Models\AttendancePunch;
use App\Models\AttendanceRegularisation;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\RegularisationManager;
use App\Services\AttendanceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Editing and deleting regularisations: HR and Super Admin may change any
 * (an approved one only with confirmation), an employee only their own while
 * it is pending. Reverting an approved correction removes only the punches
 * it wrote and rebuilds the day from the genuine biometric punches; every
 * change is audited.
 *
 * Clock: Wednesday 14 October 2026, 15:00. Work date: Tuesday 13 October.
 */
beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-10-14 15:00:00'));
    $this->shift = ShiftSetting::create([
        'name' => 'Manage Shift', 'start_time' => '09:00:00', 'end_time' => '18:00:00',
        'break_duration' => 60, 'grace_minutes' => 10, 'standard_hours' => 8, 'ot_threshold_hours' => 9,
    ]);
    $this->hr = User::factory()->create(['role' => UserRole::HrAdmin]);
});

const RMT_DATE = '2026-10-13';

function rmtEmployee(): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create([
        'user_id' => $user->id, 'status' => 'active', 'shift_id' => test()->shift->id,
        'joining_date' => '2024-01-08', 'holiday_calendar' => 'UK',
    ]);
}

function rmtRequest(Employee $employee, string $in = '09:00', string $out = '18:00', array $extra = []): AttendanceRegularisation
{
    return AttendanceRegularisation::create(array_merge([
        'employee_id' => $employee->id,
        'work_date' => RMT_DATE,
        'regularisation_type' => 'punch',
        'requested_check_in' => RMT_DATE." {$in}:00",
        'requested_check_out' => RMT_DATE." {$out}:00",
        'reason' => 'Forgot to punch at the gate',
        'status' => 'pending',
        'stage' => 'hr_review',
    ], $extra));
}

function rmtDevicePunch(Employee $employee, string $time, string $method, string $direction): AttendancePunch
{
    return AttendancePunch::factory()->create([
        'employee_id' => $employee->id, 'punched_at' => RMT_DATE.' '.$time, 'punch_date' => RMT_DATE,
        'method' => $method, 'direction' => $direction, 'source' => 'biometric',
    ]);
}

/** A biometric day (Face IN 09:12:30, Card OUT 13:00:10) corrected by HR to 09:00 → 18:00. */
function rmtApprovedOverBiometric(Employee $employee): AttendanceRegularisation
{
    rmtDevicePunch($employee, '09:12:30', 'face', 'in');
    rmtDevicePunch($employee, '13:00:10', 'id_card', 'out');
    Attendance::create([
        'employee_id' => $employee->id, 'date' => RMT_DATE,
        'check_in' => RMT_DATE.' 09:12:30', 'check_out' => RMT_DATE.' 13:00:10',
        'status' => 'late', 'is_late' => true, 'work_mode' => 'office', 'total_hours' => 3.79,
    ]);
    $request = rmtRequest($employee);
    app(AttendanceService::class)->approveRegularisation($request, test()->hr->id);

    return $request->fresh();
}

function rmtAttendance(Employee $employee): ?Attendance
{
    return Attendance::where('employee_id', $employee->id)->whereDate('date', RMT_DATE)->first();
}

function rmtEvent(string $event): ?AuditLog
{
    return AuditLog::where('event', $event)->latest('id')->first();
}

test('an employee edits their own pending request, and the change is audited', function () {
    $employee = rmtEmployee();
    $request = rmtRequest($employee);

    app(RegularisationManager::class)->update($request, $employee->user, ['check_in' => '09:30', 'check_out' => '18:15', 'reason' => 'Forgot to punch at the main gate'], 'Wrong times first time');

    $request->refresh();
    $audit = rmtEvent('ATTENDANCE_REGULARISATION_UPDATED');

    expect(Carbon::parse($request->requested_check_in)->format('H:i'))->toBe('09:30')
        ->and(Carbon::parse($request->requested_check_out)->format('H:i'))->toBe('18:15')
        ->and($request->status)->toBe('pending')
        ->and(collect($request->approval_trail)->last()['action'])->toBe('edited')
        ->and($audit->reason)->toBe('Wrong times first time')
        ->and($audit->user_id)->toBe($employee->user->id)
        ->and($audit->old_values['regularisation']['check_in'])->toBe('09:00')
        ->and($audit->new_values['regularisation']['check_in'])->toBe('09:30');
});

test('an employee deletes their own pending request without touching attendance', function () {
    $employee = rmtEmployee();
    rmtDevicePunch($employee, '09:05:00', 'face', 'in');
    $request = rmtRequest($employee);

    app(RegularisationManager::class)->delete($request, $employee->user, 'Raised by mistake');

    expect(AttendanceRegularisation::find($request->id))->toBeNull()
        ->and(AttendanceRegularisation::withTrashed()->find($request->id)->deleted_by)->toBe($employee->user->id)
        ->and(AttendancePunch::where('employee_id', $employee->id)->count())->toBe(1)
        ->and(rmtEvent('ATTENDANCE_REGULARISATION_DELETED')->reason)->toBe('Raised by mistake')
        ->and(rmtEvent('ATTENDANCE_REGULARISATION_REVERTED'))->toBeNull();
});

test('an employee cannot edit or delete an approved or rejected request', function (string $status) {
    $employee = rmtEmployee();
    $request = rmtRequest($employee, extra: ['status' => $status]);
    $manager = app(RegularisationManager::class);

    expect($manager->canEdit($employee->user, $request))->toBeFalse()
        ->and($manager->canDelete($employee->user, $request))->toBeFalse()
        ->and($manager->lockReason($employee->user, $request))->toContain('pending');

    expect(fn () => $manager->update($request, $employee->user, ['check_in' => '08:00'], 'Try anyway'))->toThrow(AuthorizationException::class);
    expect(fn () => $manager->delete($request, $employee->user, 'Try anyway'))->toThrow(AuthorizationException::class);
})->with(['approved', 'rejected']);

test('nobody but HR touches someone else\'s request — not a colleague, not a line manager', function () {
    $employee = rmtEmployee();
    $colleague = rmtEmployee();
    $lineManager = User::factory()->create(['role' => UserRole::Manager]);
    $employee->update(['manager_id' => $lineManager->id]);
    $request = rmtRequest($employee);
    $manager = app(RegularisationManager::class);

    expect($manager->canEdit($colleague->user, $request))->toBeFalse()
        ->and($manager->canDelete($lineManager, $request))->toBeFalse()
        ->and($manager->canEdit($this->hr, $request))->toBeTrue()
        ->and($manager->canDelete(User::factory()->create(['role' => UserRole::SuperAdmin]), $request))->toBeTrue();

    expect(fn () => $manager->delete($request, $colleague->user, 'Not mine'))->toThrow(AuthorizationException::class);
});

test('changing an approved request needs explicit confirmation', function () {
    $employee = rmtEmployee();
    $request = rmtApprovedOverBiometric($employee);
    $manager = app(RegularisationManager::class);

    expect(fn () => $manager->delete($request, $this->hr, 'Wrong employee'))->toThrow(DomainException::class, 'confirm')
        ->and(fn () => $manager->update($request, $this->hr, ['check_out' => '17:00'], 'Wrong out'))->toThrow(DomainException::class, 'confirm');

    expect(AttendanceRegularisation::find($request->id)->status)->toBe('approved')
        ->and(rmtAttendance($employee)->is_regularized)->toBeTrue();
});

test('deleting an approved correction reverts it and rebuilds the day from the biometric punches', function () {
    $employee = rmtEmployee();
    $request = rmtApprovedOverBiometric($employee);
    $devicePunches = AttendancePunch::where('source', 'biometric')->orderBy('id')->get()->map->only(['id', 'punched_at', 'method', 'direction', 'source', 'updated_at'])->toArray();

    expect(AttendancePunch::where('source', 'regularisation')->count())->toBe(2)
        ->and(rmtAttendance($employee)->check_out->format('H:i'))->toBe('18:00');

    app(RegularisationManager::class)->delete($request, $this->hr, 'Employee was on a half day', confirmed: true);

    $attendance = rmtAttendance($employee);
    $reverted = rmtEvent('ATTENDANCE_REGULARISATION_REVERTED');

    expect(AttendancePunch::where('source', 'regularisation')->count())->toBe(0)
        ->and(AttendancePunch::where('source', 'biometric')->orderBy('id')->get()->map->only(['id', 'punched_at', 'method', 'direction', 'source', 'updated_at'])->toArray())->toEqual($devicePunches)
        ->and($attendance->check_in->format('H:i:s'))->toBe('09:12:30')
        ->and($attendance->check_out->format('H:i:s'))->toBe('13:00:10')
        ->and($attendance->is_regularized)->toBeFalse()
        ->and($attendance->original_check_in)->toBeNull()
        ->and($attendance->is_late)->toBeTrue()
        ->and((float) $attendance->total_hours)->toBe(3.78)   // 227 whole minutes
        ->and($reverted->user_id)->toBe($this->hr->id)
        ->and($reverted->reason)->toBe('Employee was on a half day')
        ->and($reverted->old_values['attendance']['check_out'])->toBe(RMT_DATE.' 18:00')
        ->and($reverted->new_values['attendance']['check_out'])->toBe(RMT_DATE.' 13:00')
        ->and(rmtEvent('ATTENDANCE_REGULARISATION_DELETED'))->not->toBeNull()
        ->and(AttendanceRegularisation::find($request->id))->toBeNull();
});

test('a day only the correction created returns to absent when it is deleted', function () {
    $employee = rmtEmployee();
    $request = rmtRequest($employee);
    app(AttendanceService::class)->approveRegularisation($request, $this->hr->id);
    expect(rmtAttendance($employee))->not->toBeNull();

    app(RegularisationManager::class)->delete($request->fresh(), $this->hr, 'Raised for the wrong date', confirmed: true);

    expect(rmtAttendance($employee))->toBeNull()
        ->and(AttendancePunch::where('employee_id', $employee->id)->count())->toBe(0);
});

test('reverting a web-punch day restores the times recorded before the correction', function () {
    $employee = rmtEmployee();
    Attendance::create([
        'employee_id' => $employee->id, 'date' => RMT_DATE,
        'check_in' => RMT_DATE.' 09:04:00', 'check_out' => RMT_DATE.' 16:30:00',
        'status' => 'on_time', 'work_mode' => 'office', 'check_in_ip' => '10.0.0.5',
    ]);
    $request = rmtRequest($employee);
    app(AttendanceService::class)->approveRegularisation($request, $this->hr->id);

    app(RegularisationManager::class)->delete($request->fresh(), $this->hr, 'Correction not justified', confirmed: true);

    $attendance = rmtAttendance($employee);

    expect($attendance->check_in->format('H:i'))->toBe('09:04')
        ->and($attendance->check_out->format('H:i'))->toBe('16:30')
        ->and($attendance->is_regularized)->toBeFalse();
});

test('HR correcting an approved request reverts, then re-applies the new times', function () {
    $employee = rmtEmployee();
    $request = rmtApprovedOverBiometric($employee);

    app(RegularisationManager::class)->update($request, $this->hr, ['check_in' => '09:12', 'check_out' => '17:30'], 'Gate log shows 17:30', confirmed: true);

    $attendance = rmtAttendance($employee);
    $request->refresh();
    $audit = rmtEvent('ATTENDANCE_REGULARISATION_CORRECTED');

    expect($attendance->check_out->format('H:i'))->toBe('17:30')
        ->and($attendance->is_regularized)->toBeTrue()
        ->and($attendance->original_check_out->format('H:i:s'))->toBe('13:00:10')   // the genuine biometric value
        ->and(AttendancePunch::where('source', 'regularisation')->pluck('punched_at')->map->format('H:i')->sort()->values()->all())->toBe(['09:12', '17:30'])
        ->and(AttendancePunch::where('source', 'biometric')->count())->toBe(2)
        ->and($request->status)->toBe('approved')
        ->and($request->applied_via)->toBe('hr_correction')
        ->and(collect($request->approval_trail)->last()['action'])->toBe('corrected')
        ->and($audit->old_values['attendance']['check_out'])->toBe(RMT_DATE.' 18:00')
        ->and($audit->new_values['attendance']['check_out'])->toBe(RMT_DATE.' 17:30')
        ->and($audit->reason)->toBe('Gate log shows 17:30');
});

test('the overtime a correction filed is withdrawn on revert, and paid overtime blocks it', function () {
    $employee = rmtEmployee();
    $request = rmtRequest($employee, '08:00', '20:00');
    app(AttendanceService::class)->approveRegularisation($request, $this->hr->id);
    $ot = OtRequest::where('employee_id', $employee->id)->firstOrFail();
    expect($ot->status)->toBe('approved')->and(OvertimeRecord::count())->toBe(1);

    OvertimeRecord::query()->update(['is_paid' => true]);
    expect(fn () => app(RegularisationManager::class)->delete($request->fresh(), $this->hr, 'Wrong day', confirmed: true))
        ->toThrow(DomainException::class, 'paid');
    expect(AttendanceRegularisation::find($request->id))->not->toBeNull();

    OvertimeRecord::query()->update(['is_paid' => false]);
    app(RegularisationManager::class)->delete($request->fresh(), $this->hr, 'Wrong day', confirmed: true);

    expect($ot->fresh()->status)->toBe('cancelled')
        ->and(OvertimeRecord::count())->toBe(0);
});

test('an approved leave regularisation is locked here — the leave ledger owns it', function () {
    $employee = rmtEmployee();
    $request = rmtRequest($employee, extra: ['status' => 'approved', 'category' => 'leave']);

    expect(app(RegularisationManager::class)->canDelete($this->hr, $request))->toBeFalse()
        ->and(app(RegularisationManager::class)->lockReason($this->hr, $request))->toContain('leave');
});

test('approving never rewrites a device punch that sits on the corrected second', function () {
    $employee = rmtEmployee();
    $device = rmtDevicePunch($employee, '09:00:00', 'face', 'in');
    $request = rmtRequest($employee);

    app(AttendanceService::class)->approveRegularisation($request, $this->hr->id);
    app(RegularisationManager::class)->delete($request->fresh(), $this->hr, 'Revert it', confirmed: true);

    $device->refresh();

    expect($device->source)->toBe('biometric')
        ->and($device->method)->toBe('face')
        ->and(AttendancePunch::where('employee_id', $employee->id)->count())->toBe(1);
});

test('My Attendance shows Edit and Delete only on the employee\'s pending requests', function () {
    $employee = rmtEmployee();
    $pending = rmtRequest($employee);
    $approved = rmtRequest($employee, extra: ['status' => 'approved', 'work_date' => '2026-10-12']);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->assertSee('My regularisations')
        ->assertSeeHtml('wire:click="openRegularisationEdit('.$pending->id.')"')
        ->assertSeeHtml('wire:click="openRegularisationDelete('.$pending->id.')"')
        ->assertDontSeeHtml('wire:click="openRegularisationEdit('.$approved->id.')"')
        ->call('openRegularisationDelete', $pending->id)
        ->assertSet('showManageRegModal', true)
        ->call('confirmRegularisationDelete')
        ->assertHasErrors('manageRegReason')
        ->set('manageRegReason', 'Not needed any more')
        ->call('confirmRegularisationDelete')
        ->assertHasNoErrors()
        ->assertSet('showManageRegModal', false);

    expect(AttendanceRegularisation::find($pending->id))->toBeNull();
});

test('an employee cannot open someone else\'s request through the component', function () {
    $employee = rmtEmployee();
    $other = rmtRequest(rmtEmployee());

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->call('openRegularisationEdit', $other->id)
        ->assertForbidden();
});

test('HR edits from All Attendance, and an approved delete waits for the confirmation tick', function () {
    $employee = rmtEmployee();
    $request = rmtApprovedOverBiometric($employee);

    Livewire::actingAs($this->hr)->test(AllAttendance::class)
        ->assertSeeHtml('data-decided-regularisations')
        ->assertSeeHtml('wire:click="openRegularisationDelete('.$request->id.')"')
        ->call('openRegularisationDelete', $request->id)
        ->assertSee('Revert & delete')
        ->set('manageRegReason', 'Duplicate correction')
        ->call('confirmRegularisationDelete')
        ->assertHasErrors('manageRegConfirm')
        ->set('manageRegConfirm', true)
        ->call('confirmRegularisationDelete')
        ->assertHasNoErrors();

    expect(AttendanceRegularisation::find($request->id))->toBeNull()
        ->and(rmtAttendance($employee)->check_in->format('H:i:s'))->toBe('09:12:30');
});
