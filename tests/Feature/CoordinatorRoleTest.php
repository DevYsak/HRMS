<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AttendanceExceptions;
use App\Livewire\Settings\CoordinatorAssignments;
use App\Models\Attendance;
use App\Models\AttendanceRegularisation;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\CoordinatorAssignment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\NotificationSetting;
use App\Models\User;
use App\Notifications\AttendanceExceptionNotification;
use App\Services\Attendance\CoordinatorService;
use App\Services\EmployeeMenu;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * The Coordinator role: monitors attendance exceptions for the employees and
 * departments HR assigns, reminds and escalates — once per issue — and
 * holds no approval, payroll or settings power.
 *
 * Clock: Wednesday 14 October 2026, 15:00.
 */
beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-14 15:00:00')));

function crdCoordinator(): User
{
    $user = User::factory()->create(['role' => UserRole::Coordinator]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    return $user->fresh();
}

function crdEmployee(?Department $department = null, ?User $manager = null): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create([
        'user_id' => $user->id, 'status' => 'active', 'department_id' => $department?->id,
        'manager_id' => $manager?->id, 'joining_date' => '2024-01-08', 'holiday_calendar' => 'UK',
    ]);
}

function crdAssign(User $coordinator, ?Department $department = null, ?Employee $employee = null): void
{
    CoordinatorAssignment::create(['coordinator_user_id' => $coordinator->id, 'department_id' => $department?->id, 'employee_id' => $employee?->id]);
}

function crdInbox(User $user): int
{
    return $user->notifications()->where('type', AttendanceExceptionNotification::class)->count();
}

test('a coordinator monitors attendance but cannot approve, pay or configure', function () {
    $coordinator = crdCoordinator();

    expect($coordinator->hasPermission('monitor_attendance_exceptions'))->toBeTrue()
        ->and($coordinator->hasPermission('remind_employees'))->toBeTrue()
        ->and($coordinator->hasPermission('apply_leave'))->toBeTrue()
        ->and($coordinator->canApproveLeave())->toBeFalse()
        ->and($coordinator->canApproveRegularisations())->toBeFalse()
        ->and($coordinator->canRunPayroll())->toBeFalse()
        ->and($coordinator->canManageSettings())->toBeFalse()
        ->and($coordinator->canManageEmployees())->toBeFalse()
        ->and($coordinator->hasPermission('assign_coordinators'))->toBeFalse();
});

test('a coordinator monitors only what HR assigned', function () {
    $coordinator = crdCoordinator();
    $sales = Department::factory()->create();
    $inSales = crdEmployee($sales);
    $named = crdEmployee();
    $other = crdEmployee();
    $service = app(CoordinatorService::class);

    expect($service->monitoredEmployeeIds($coordinator))->toBe([]);

    crdAssign($coordinator, department: $sales);
    crdAssign($coordinator, employee: $named);

    expect($service->monitoredEmployeeIds($coordinator))->toContain($inSales->id, $named->id)->not->toContain($other->id);
});

test('exceptions: absent, late, missing check-out and pending regularisation — never weekends', function () {
    AttendanceSetting::firstOrCreate([])->update(['coordinator_late_minutes' => 10]);
    $coordinator = crdCoordinator();
    $absent = crdEmployee();
    $late = crdEmployee();
    $missing = crdEmployee();
    foreach ([$absent, $late, $missing] as $e) {
        crdAssign($coordinator, employee: $e);
    }
    Attendance::create(['employee_id' => $late->id, 'date' => '2026-10-13', 'check_in' => '2026-10-13 09:45:00', 'check_out' => '2026-10-13 18:00:00', 'status' => 'late', 'is_late' => true, 'late_minutes' => 40, 'work_mode' => 'office']);
    Attendance::create(['employee_id' => $missing->id, 'date' => '2026-10-13', 'check_in' => '2026-10-13 09:00:00', 'status' => 'on_time', 'work_mode' => 'office']);
    Attendance::create(['employee_id' => $absent->id, 'date' => '2026-10-12', 'check_in' => '2026-10-12 09:00:00', 'check_out' => '2026-10-12 18:00:00', 'status' => 'on_time', 'work_mode' => 'office']);
    AttendanceRegularisation::create(['employee_id' => $missing->id, 'work_date' => '2026-10-13', 'requested_check_in' => '2026-10-13 09:00:00', 'requested_check_out' => '2026-10-13 18:00:00', 'reason' => 'Forgot', 'status' => 'pending']);
    $service = app(CoordinatorService::class);

    $tuesday = $service->exceptions($coordinator, Carbon::parse('2026-10-13'));
    $saturday = $service->exceptions($coordinator, Carbon::parse('2026-10-10'));

    expect($tuesday['absent']->pluck('employee_id')->all())->toBe([$absent->id])
        ->and($tuesday['late']->pluck('employee_id')->all())->toBe([$late->id])
        ->and($tuesday['missing_checkout']->pluck('employee_id')->all())->toBe([$missing->id])
        ->and($tuesday['regularisation']->pluck('employee_id')->all())->toBe([$missing->id])
        ->and($saturday['absent'])->toBeEmpty();
});

test('a reminder reaches the employee once per issue per day, and is audited', function () {
    $coordinator = crdCoordinator();
    $employee = crdEmployee();
    crdAssign($coordinator, employee: $employee);
    $service = app(CoordinatorService::class);

    expect($service->remind($coordinator, $employee, 'absent', '2026-10-13'))->toBeTrue()
        ->and($service->remind($coordinator, $employee, 'absent', '2026-10-13'))->toBeFalse()
        ->and(crdInbox($employee->user))->toBe(1)
        ->and(AuditLog::where('event', 'ATTENDANCE_EXCEPTION_REMINDED')->count())->toBe(1);
});

test('escalation goes to the manager or HR once, and never to an unmonitored employee', function () {
    $coordinator = crdCoordinator();
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = crdEmployee(manager: $manager);
    $stranger = crdEmployee();
    crdAssign($coordinator, employee: $employee);
    $service = app(CoordinatorService::class);

    expect($service->escalate($coordinator, $employee, 'missing_checkout', '2026-10-13', 'manager'))->toBe(1)
        ->and($service->escalate($coordinator, $employee, 'missing_checkout', '2026-10-13', 'manager'))->toBe(0)
        ->and($service->escalate($coordinator, $employee, 'missing_checkout', '2026-10-13', 'hr'))->toBeGreaterThanOrEqual(1)
        ->and(crdInbox($manager))->toBe(1)
        ->and(crdInbox($hr))->toBe(1);

    expect(fn () => $service->remind($coordinator, $stranger, 'absent', '2026-10-13'))->toThrow(AuthorizationException::class);
});

test('the digest does not repeat, and repeats an unresolved issue only after the interval', function () {
    AttendanceSetting::firstOrCreate([])->update(['coordinator_reminder_hours' => 2]);
    $coordinator = crdCoordinator();
    crdAssign($coordinator, employee: crdEmployee()); // absent today (15:00 is past shift start)

    $this->artisan('hrms:coordinator-attendance-alerts')->assertSuccessful();
    $this->artisan('hrms:coordinator-attendance-alerts')->assertSuccessful();
    expect(crdInbox($coordinator))->toBe(1);

    $this->travelTo(Carbon::parse('2026-10-14 17:05:00'));
    $this->artisan('hrms:coordinator-attendance-alerts')->assertSuccessful();
    expect(crdInbox($coordinator))->toBe(2);
});

test('excluding the Coordinator role on the notification settings silences the digest', function () {
    NotificationSetting::create(['key' => AttendanceExceptionNotification::class, 'label' => 'Attendance exceptions', 'group' => 'Attendance', 'mail_enabled' => true, 'database_enabled' => true, 'is_automatic' => true, 'exclude_roles' => ['coordinator']]);
    $coordinator = crdCoordinator();
    crdAssign($coordinator, employee: crdEmployee());

    $this->artisan('hrms:coordinator-attendance-alerts')->assertSuccessful();

    expect(crdInbox($coordinator))->toBe(0);
});

test('the exceptions page is for coordinators and HR; the menu item for coordinators', function () {
    $coordinator = crdCoordinator();
    $employee = crdEmployee();
    crdAssign($coordinator, employee: $employee);

    Livewire::actingAs($coordinator)->test(AttendanceExceptions::class)
        ->set('date', '2026-10-13')
        ->assertSee($employee->user->name)
        ->call('remind', $employee->id, 'absent', '2026-10-13');
    expect(crdInbox($employee->user))->toBe(1);

    $this->actingAs($employee->user)->get(route('attendance.exceptions'))->assertForbidden();

    $this->actingAs($coordinator);
    expect(collect(app(EmployeeMenu::class)->visible())->pluck('key'))->toContain('exceptions');
    $this->actingAs($employee->user);
    expect(collect(app(EmployeeMenu::class)->visible())->pluck('key'))->not->toContain('exceptions');
});

test('HR assigns coordinators; nobody else can', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $coordinator = crdCoordinator();
    $department = Department::factory()->create();

    Livewire::actingAs($hr)->test(CoordinatorAssignments::class)
        ->set('coordinatorId', $coordinator->id)
        ->set('addDepartmentId', (string) $department->id)
        ->call('assign', 'department')
        ->assertHasNoErrors()
        ->set('reminderHours', 3)
        ->call('saveThresholds');

    expect(CoordinatorAssignment::where('coordinator_user_id', $coordinator->id)->where('department_id', $department->id)->exists())->toBeTrue()
        ->and((int) AttendanceSetting::first()->coordinator_reminder_hours)->toBe(3)
        ->and(AuditLog::where('event', 'COORDINATOR_ASSIGNED')->exists())->toBeTrue();

    Livewire::actingAs($coordinator)->test(CoordinatorAssignments::class)->assertForbidden();
});
