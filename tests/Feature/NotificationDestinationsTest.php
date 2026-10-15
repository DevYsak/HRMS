<?php

use App\Enums\EmployeeStatus;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Notifications\EmployeeStatusChangedNotification;
use App\Notifications\OffboardingCompletedNotification;
use App\Notifications\ProbationConfirmedNotification;
use App\Notifications\ProbationExtendedNotification;

/**
 * Every notification link opens a page its recipient may see (Phase 1
 * audit: several sent employees and line managers to HR's employee editor,
 * and one to an /employees/{id} page that does not exist).
 */
function ndUser(string $slug, Department $department, ?int $managerUserId = null): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => $managerUserId]);

    return $user->fresh();
}

beforeEach(function () {
    $this->department = Department::factory()->create();
    $this->manager = ndUser('manager', $this->department);
    $this->employeeUser = ndUser('employee', $this->department, $this->manager->id);
    $this->employee = $this->employeeUser->employee;
    $this->hr = ndUser('hr_admin', $this->department);
});

/** Open the link as the recipient: it must land on a real page they may see. */
function ndOpens($test, User $recipient, string $url): void
{
    $response = $test->actingAs($recipient)->get($url);
    if ($response->isRedirect()) {
        $response = $test->actingAs($recipient)->get($response->headers->get('Location'));
    }
    expect($response->getStatusCode())->toBe(200, "{$url} returned {$response->getStatusCode()}");
}

test('the employee\'s status-change notice opens their own profile', function () {
    $data = (new EmployeeStatusChangedNotification($this->employee, EmployeeStatus::Probation, EmployeeStatus::Confirmed, $this->hr))->toArray($this->employeeUser);

    expect($data['url'])->toBe(route('profile.me'));
    ndOpens($this, $this->employeeUser, $data['url']);
});

test('the employee\'s probation-confirmed notice opens their own profile', function () {
    $data = (new ProbationConfirmedNotification($this->employee, 'hr_approved'))->forRole('employee')->toArray($this->employeeUser);

    ndOpens($this, $this->employeeUser, $data['url']);
});

test('HR\'s probation-review notice opens the probation page', function () {
    $data = (new ProbationConfirmedNotification($this->employee, 'manager_confirmed'))->forRole('hr_admin')->toArray($this->hr);

    ndOpens($this, $this->hr, $data['url']);
});

test('the line manager\'s probation-extended notice opens the probation page they can act on', function () {
    $this->employee->update(['status' => 'probation']);
    $data = (new ProbationExtendedNotification($this->employee->fresh()))->toArray($this->manager);

    expect($data['url'])->toBe(route('employees.probation', $this->employee));
    ndOpens($this, $this->manager, $data['url']);
    $this->actingAs($this->manager)->get($data['url'])->assertSee('Stage 1');
});

test('a manager cannot open the probation page of someone outside their team', function () {
    $stranger = ndUser('employee', Department::factory()->create());

    $this->actingAs($this->manager)->get(route('employees.probation', $stranger->employee))->assertForbidden();
});

test('the offboarding-completed notice opens a real page for the employee and for HR', function () {
    $notification = new OffboardingCompletedNotification($this->employee);

    ndOpens($this, $this->employeeUser, $notification->forRole('employee')->toArray($this->employeeUser)['url']);
    ndOpens($this, $this->hr, (new OffboardingCompletedNotification($this->employee))->forRole('hr_admin')->toArray($this->hr)['url']);
});
