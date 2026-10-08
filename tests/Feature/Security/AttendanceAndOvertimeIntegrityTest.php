<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AllAttendance;
use App\Livewire\Attendance\BiometricControl;
use App\Livewire\Attendance\BiometricSummary;
use App\Livewire\Overtime\ManageOtRequests;
use App\Livewire\Overtime\NexflowOtPanel;
use App\Livewire\Overtime\OtWindows;
use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OtRequest;
use App\Models\OtWindow;
use App\Models\PublicHoliday;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\HolidayResolver;
use App\Services\Attendance\ShiftResolver;
use App\Services\AttendanceService;
use App\Services\Biometric\EngineAttendanceSyncService;
use App\Services\LeaveService;
use App\Services\OvertimeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Spec v3.1 §3.2 / §3.4 / §4 — attendance and overtime: who can see and act
 * on whose records, and the rules that keep the recorded day honest.
 */
function attUser(UserRole $role, array $employee = []): User
{
    $user = User::factory()->create(['role' => $role]);
    Employee::factory()->create(array_merge(['user_id' => $user->id, 'status' => 'active', 'manager_id' => null], $employee));

    return $user->fresh();
}

function complianceItShift(): ShiftSetting
{
    return ShiftSetting::create([
        'name' => 'IT Shift', 'start_time' => '10:30:00', 'end_time' => '19:30:00',
        'break_duration' => 60, 'grace_minutes' => 5, 'standard_hours' => 9,
    ]);
}

function complianceUkShift(): ShiftSetting
{
    return ShiftSetting::create([
        'name' => 'UK Sales Shift', 'start_time' => '13:00:00', 'end_time' => '22:00:00',
        'break_duration' => 60, 'grace_minutes' => 5, 'standard_hours' => 9,
    ]);
}

beforeEach(fn () => Notification::fake());

// ── Overtime ────────────────────────────────────────────────────────────────

test('the Nexflow panel never lets the browser rewrite what gets paid', function () {
    $hr = attUser(UserRole::HrAdmin);

    Livewire::actingAs($hr)->test(NexflowOtPanel::class)
        ->set('data', ['ot_records' => [['id' => 1, 'status' => 'approved', 'ot_hours' => 40]]]);
})->throws(CannotUpdateLockedPropertyException::class);

test('a manager cannot import Nexflow overtime for themselves', function () {
    $manager = attUser(UserRole::Manager);

    Livewire::actingAs($manager)->test(NexflowOtPanel::class)
        ->call('selectEmployee', $manager->employee->id)
        ->call('importAllApproved')
        ->assertForbidden();
});

test('a manager cannot select someone outside their team on the Nexflow panel', function () {
    $manager = attUser(UserRole::Manager);
    $stranger = attUser(UserRole::Employee);

    Livewire::actingAs($manager)->test(NexflowOtPanel::class)
        ->call('selectEmployee', $stranger->employee->id)
        ->assertForbidden();
});

test('an approved OT request cannot be edited after the fact', function () {
    $manager = attUser(UserRole::Manager);
    $employee = attUser(UserRole::Employee, ['manager_id' => $manager->id]);
    $request = OtRequest::create([
        'employee_id' => $employee->employee->id, 'work_date' => now()->subDay()->toDateString(),
        'start_time' => '19:30', 'end_time' => '21:30', 'requested_hours' => 2, 'reason' => 'Release', 'status' => 'approved',
    ]);

    Livewire::actingAs($manager)->test(ManageOtRequests::class)
        ->call('openEdit', $request->id)
        ->assertSet('showEditModal', false)
        ->assertSet('reviewingId', null);

    expect((float) $request->fresh()->requested_hours)->toBe(2.0);
});

test('an OT request is accepted without any OT window (D6: windows are planning only)', function () {
    $employee = attUser(UserRole::Employee);
    $date = now()->addDay()->toDateString();

    expect(OtWindow::count())->toBe(0);

    $request = app(OvertimeService::class)->submitRequest($employee->employee, [
        'work_date' => $date, 'start_time' => '19:30', 'end_time' => '21:30', 'reason' => 'Client release',
    ]);

    expect($request->status)->toBe('pending')->and((float) $request->requested_hours)->toBe(2.0);
});

test('HR can still open an OT window for planning', function () {
    $hr = attUser(UserRole::HrAdmin);

    Livewire::actingAs($hr)->test(OtWindows::class)
        ->set('title', 'Release week')->set('startsAt', now()->toDateString())->set('endsAt', now()->addDays(7)->toDateString())
        ->call('open')
        ->assertHasNoErrors();

    expect(OtWindow::count())->toBe(1);
});

test('a manager cannot open OT windows', function () {
    Livewire::actingAs(attUser(UserRole::Manager))->test(OtWindows::class)->assertForbidden();

    expect(OtWindow::count())->toBe(0);
});

// ── Scope of attendance data ────────────────────────────────────────────────

test('a manager\'s attendance export contains only their own team', function () {
    $manager = attUser(UserRole::Manager);
    $mine = attUser(UserRole::Employee, ['manager_id' => $manager->id]);
    $stranger = attUser(UserRole::Employee);
    foreach ([$mine, $stranger] as $u) {
        Attendance::create(['employee_id' => $u->employee->id, 'date' => now()->toDateString(), 'check_in' => now()->setTime(10, 20), 'status' => 'on_time']);
    }

    $response = Livewire::actingAs($manager)->test(AllAttendance::class)->call('exportCsv');
    $csv = $response->effects['download']['content'] ?? '';
    $csv = base64_decode($csv, true) ?: $csv;

    expect($csv)->toContain($mine->name)->not->toContain($stranger->name);
});

test('the biometric fleet page and machine sync are HR-only', function () {
    $manager = attUser(UserRole::Manager);

    Livewire::actingAs($manager)->test(BiometricControl::class)->assertForbidden();

    Livewire::actingAs(attUser(UserRole::HrAdmin))->test(BiometricControl::class)->assertOk();
});

test('a manager cannot re-pull a day from the attendance engine', function () {
    config(['services.biometric_app.url' => 'http://engine.test']);
    Http::fake();

    Livewire::actingAs(attUser(UserRole::Manager))->test(BiometricSummary::class)
        ->call('syncNow')
        ->assertForbidden();

    Http::assertNothingSent();
});

test('the dashboard-v2 proxy no longer forwards device actions, and managers cannot use it', function () {
    config(['services.biometric_app.url' => 'http://engine.test']);
    Http::fake();

    $this->actingAs(attUser(UserRole::HrAdmin))
        ->getJson(route('attendance.dashboard-v2.proxy', ['path' => 'set-time']).'?delta_min=-90')
        ->assertNotFound();

    $this->actingAs(attUser(UserRole::Manager))
        ->getJson(route('attendance.dashboard-v2.proxy', ['path' => 'dashboard']))
        ->assertForbidden();

    Http::assertNothingSent();
});

// ── Regularisation ──────────────────────────────────────────────────────────

test('an already-decided regularisation cannot be rejected by an ordinary approver', function () {
    $manager = attUser(UserRole::Manager);
    $employee = attUser(UserRole::Employee, ['manager_id' => $manager->id]);
    $reg = AttendanceRegularisation::create([
        'employee_id' => $employee->employee->id, 'work_date' => now()->subDay()->toDateString(),
        'requested_check_in' => '10:30', 'requested_check_out' => '19:30', 'reason' => 'Forgot to punch', 'status' => 'approved',
    ]);

    expect(fn () => app(AttendanceService::class)->rejectRegularisation($reg, $manager->id, 'Changed my mind'))
        ->toThrow(DomainException::class, 'already been decided');

    expect($reg->fresh()->status)->toBe('approved');
});

test('the engine sync never overwrites an approved regularisation', function () {
    config(['services.biometric_app.url' => 'http://engine.test']);
    $employee = attUser(UserRole::Employee, ['employee_code' => 7001]);
    $date = now()->subDay()->toDateString();
    // A punch correction, as applyRegularisation leaves it: corrected times
    // plus the snapshot of the original device punch.
    $attendance = Attendance::create([
        'employee_id' => $employee->employee->id, 'date' => $date,
        'check_in' => "{$date} 10:30:00", 'check_out' => "{$date} 19:30:00", 'total_hours' => 9,
        'original_check_in' => "{$date} 11:40:00", 'original_check_out' => null,
        'status' => 'on_time', 'is_regularized' => true,
    ]);
    Http::fake(['*/api/dashboard*' => Http::response(['table' => [[
        'emp_id' => 7001, 'first_punch' => '11:40:00', 'last_punch' => '15:00:00',
        'working_min' => 200, 'break_min' => 0, 'late' => true, 'delay_min' => 70,
    ]]], 200)]);

    app(EngineAttendanceSyncService::class)->syncDate($date);

    $fresh = $attendance->fresh();
    expect($fresh->check_in->format('H:i'))->toBe('10:30')
        ->and($fresh->check_out->format('H:i'))->toBe('19:30')
        ->and((float) $fresh->total_hours)->toBe(9.0)
        ->and($fresh->is_late)->toBeFalse();
});

// ── Late flag at the grace boundary (spec §3.2) ─────────────────────────────

test('10:35 is on time and 10:36 is late on the IT shift, to the minute', function () {
    $employee = attUser(UserRole::Employee, ['shift_id' => complianceItShift()->id]);
    $shift = app(ShiftResolver::class)->resolve($employee->employee->fresh(), Carbon::today());

    expect($shift->isLate(Carbon::today()->setTime(10, 35, 0)))->toBeFalse()
        ->and($shift->isLate(Carbon::today()->setTime(10, 35, 59)))->toBeFalse()
        ->and($shift->isLate(Carbon::today()->setTime(10, 36, 0)))->toBeTrue()
        ->and($shift->lateMinutes(Carbon::today()->setTime(10, 36, 40)))->toBe(1);
});

// ── Missing check-out (spec §3.2: shift end + 1 hour) ───────────────────────

test('a UK shift employee still working at 21:00 is not flagged; at 23:05 they are', function () {
    $this->travelTo(now()->setTime(21, 0));
    $employee = attUser(UserRole::Employee, ['shift_id' => complianceUkShift()->id]);
    $attendance = Attendance::create(['employee_id' => $employee->employee->id, 'date' => now()->toDateString(), 'check_in' => now()->setTime(13, 0), 'status' => 'on_time']);

    $this->artisan('hrms:flag-missing-checkouts')->assertSuccessful();
    expect($attendance->fresh()->missing_checkout)->toBeFalse();

    $this->travelTo(now()->setTime(23, 5));
    $this->artisan('hrms:flag-missing-checkouts')->assertSuccessful();
    expect($attendance->fresh()->missing_checkout)->toBeTrue();
});

test('a real clock-out clears a missing check-out flag', function () {
    $employee = attUser(UserRole::Employee);
    $attendance = Attendance::create([
        'employee_id' => $employee->employee->id, 'date' => now()->toDateString(),
        'check_in' => now()->subHours(9), 'status' => 'on_time', 'missing_checkout' => true,
    ]);

    app(AttendanceService::class)->checkOut($attendance);

    expect($attendance->fresh()->missing_checkout)->toBeFalse();
});

// ── Unauthorised-absence job (spec §3.3: holidays and MDL consume no leave) ─

function unaType(): LeaveType
{
    return LeaveType::create(['name' => 'Unauthorized Absence', 'code' => 'UNA', 'category' => 'unauthorized', 'is_paid' => false]);
}

test('nobody is booked unpaid leave on a public holiday, an MDL day or before they joined', function () {
    unaType();
    $employee = attUser(UserRole::Employee, ['joining_date' => '2024-01-08']);
    $newJoiner = attUser(UserRole::Employee, ['joining_date' => '2026-12-01']);

    PublicHoliday::factory()->create(['date' => '2026-08-31', 'name' => 'Summer Bank Holiday', 'is_active' => true,
        'country' => app(HolidayResolver::class)->resolveCountry($employee->employee)]);
    DecemberMandatoryDay::create(['year' => 2026, 'date' => '2026-12-29', 'description' => 'Company shutdown']);

    $service = app(LeaveService::class);
    $service->autoFlagUnauthorizedAbsences(Carbon::parse('2026-08-31'));
    $service->autoFlagUnauthorizedAbsences(Carbon::parse('2026-12-29'));
    $service->autoFlagUnauthorizedAbsences(Carbon::parse('2026-11-25')); // before the new joiner started

    expect(LeaveRequest::where('employee_id', $newJoiner->employee->id)->count())->toBe(0)
        ->and(LeaveRequest::where('employee_id', $employee->employee->id)->pluck('start_date')->map->toDateString()->all())
        ->toBe(['2026-11-25']); // a real working day with no attendance still counts
});

test('the review command lists wrongly flagged holiday absences and cancels them only with --apply', function () {
    $type = unaType();
    $employee = attUser(UserRole::Employee, ['joining_date' => '2024-01-08']);
    PublicHoliday::factory()->create(['date' => '2026-08-31', 'name' => 'Summer Bank Holiday', 'is_active' => true,
        'country' => app(HolidayResolver::class)->resolveCountry($employee->employee)]);
    $wrong = LeaveRequest::create([
        'employee_id' => $employee->employee->id, 'leave_type_id' => $type->id, 'start_date' => '2026-08-31', 'end_date' => '2026-08-31',
        'days' => 1, 'reason' => 'Auto-flagged: absent without approved leave or regularisation.', 'status' => 'approved',
        'requested_leave_status' => 'unpaid', 'approved_leave_status' => 'unpaid',
    ]);
    $this->travelTo(Carbon::parse('2026-10-04'));

    $this->artisan('hrms:review-auto-absences')->expectsOutputToContain('1 found; 1 can be cancelled')->assertSuccessful();
    expect($wrong->fresh()->status)->toBe('approved');

    $this->artisan('hrms:review-auto-absences', ['--apply' => true])->assertSuccessful();
    expect($wrong->fresh()->status)->toBe('cancelled');
});
