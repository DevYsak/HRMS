<?php

use App\Enums\UserRole;
use App\Livewire\Dashboard;
use App\Livewire\ManagerDashboard;
use App\Models\Attendance;
use App\Models\DecemberMandatoryDay;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\WfhRequest;
use App\Services\EmployeeDashboardService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Follow-ups from the review of the dashboard changes: each role's page is a
 * full page inside the app shell, company-wide figures stay with company-wide
 * accounts, and the employee card is truthful about WFH and shutdown days.
 */
function landingUser(UserRole $role, array $user = [], array $employee = []): User
{
    $account = User::factory()->create(array_merge(['role' => $role], $user));
    Employee::factory()->create(array_merge(['user_id' => $account->id, 'status' => 'active', 'manager_id' => null], $employee));

    return $account->fresh();
}

/** The element holding <flux:main>: the app-shell grid only works when it is <body>. */
function fluxMainParent(string $html): ?string
{
    $dom = new DOMDocument;
    @$dom->loadHTML($html);

    return (new DOMXPath($dom))->query('//*[@data-flux-main]')->item(0)?->parentNode?->nodeName;
}

beforeEach(fn () => Notification::fake());

/** D1: company-wide reach is a deliberate grant on the Director role. */
function grantDirectorCompanyWide(): void
{
    $role = Role::where('slug', 'director')->firstOrFail();
    $role->permissions()->updateExistingPivot(Permission::where('key', 'manage_employees')->value('id'), ['scope' => 'all']);
    $role->flushPermissionCache();
}

test('each role is forwarded from "/" to its own page, which sits directly in the app shell', function (UserRole $role, string $route) {
    $user = landingUser($role);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route($route));

    $page = $this->actingAs($user)->get(route($route))->assertOk();
    expect(fluxMainParent($page->getContent()))->toBe('body');
})->with([
    'manager' => [UserRole::Manager, 'dashboard.manager'],
    'finance' => [UserRole::Finance, 'dashboard.finance'],
    // D1 (8 Oct 2026): a Director is department-scoped by default.
    'director' => [UserRole::Director, 'dashboard.department'],
]);

test('an employee stays on "/" and gets self-service inside the app shell', function () {
    $page = $this->actingAs(landingUser(UserRole::Employee))->get(route('dashboard'))->assertOk();

    expect(fluxMainParent($page->getContent()))->toBe('body');
});

test('a role that cannot open its own dashboard page stays on self-service instead of a 403', function () {
    $bare = Role::create(['name' => 'Bare manager', 'slug' => 'bare-manager', 'is_system' => false, 'is_active' => true]);
    $manager = landingUser(UserRole::Manager, ['role_id' => $bare->id]);

    Livewire::actingAs($manager)->test(Dashboard::class)
        ->assertNoRedirect()
        ->assertSee('Attendance Overview');
});

test('a scoped Director lands on the department view and cannot open the company-wide dashboard', function () {
    $uk = Department::factory()->create(['name' => 'UK Sales']);
    $director = landingUser(UserRole::Director, ['scope_departments' => [$uk->id]], ['department_id' => $uk->id]);

    $this->actingAs($director)->get(route('dashboard'))->assertRedirect(route('dashboard.department'));
    $this->actingAs($director)->get(route('dashboard.director'))->assertForbidden();
    $this->actingAs($director)->get(route('dashboard.executive'))->assertForbidden();
    $this->actingAs($director)->get(route('dashboard.manager'))->assertOk();

    // D1: an unscoped Director is still department-level by default; only a
    // Director granted company-wide reach gets the executive view.
    $this->actingAs(landingUser(UserRole::Director))->get(route('dashboard.director'))->assertForbidden();

    grantDirectorCompanyWide();
    $this->actingAs(landingUser(UserRole::Director))->get(route('dashboard.director'))
        ->assertOk()->assertSee('Executive Summary');
});

test('working from home needs an approved request, on the card and on the server', function () {
    $me = landingUser(UserRole::Employee);

    Livewire::actingAs($me)->test(Dashboard::class)
        ->assertDontSee('aria-label="Work mode"', false)
        ->call('clockIn', null, null, 'wfh');

    expect(Attendance::where('employee_id', $me->employee->id)->exists())->toBeFalse();

    WfhRequest::create(['employee_id' => $me->employee->id, 'start_date' => today()->toDateString(), 'end_date' => today()->toDateString(),
        'reason' => 'Boiler repair', 'status' => 'approved']);

    Livewire::actingAs($me)->test(Dashboard::class)->assertSee('aria-label="Work mode"', false);
});

test('a Mandatory December Leave day reads as a shutdown day, not an absence', function () {
    // Wednesday 30 Dec 2026, after any shift has ended.
    $this->travelTo(Carbon::parse('2026-12-30 21:30'));
    DecemberMandatoryDay::create(['year' => 2026, 'date' => '2026-12-30', 'description' => 'Year-end shutdown']);
    $me = landingUser(UserRole::Employee, [], ['joining_date' => '2024-01-08']);

    $today = app(EmployeeDashboardService::class)->build($me)['today'];

    expect($today['state'])->toBe('mdl')
        ->and($today['label'])->toBe('MDL shutdown')
        ->and($today['is_working_day'])->toBeFalse();
});

test('the team view counts only people working now, while a leaver\'s open request can still be decided', function () {
    $manager = landingUser(UserRole::Manager);
    $current = landingUser(UserRole::Employee, [], ['manager_id' => $manager->id]);
    $leaver = landingUser(UserRole::Employee, [], ['manager_id' => $manager->id, 'status' => 'resigned']);
    $type = LeaveType::create(['name' => 'Casual', 'annual_allocation_days' => 0]);
    LeaveRequest::create([
        'employee_id' => $leaver->employee->id, 'leave_type_id' => $type->id,
        'start_date' => today()->addDays(3)->toDateString(), 'end_date' => today()->addDays(3)->toDateString(),
        'days' => 1, 'reason' => 'Handover trip', 'status' => 'pending',
    ]);

    $page = Livewire::actingAs($manager)->test(ManagerDashboard::class)->assertOk();

    expect($page->viewData('teamAttendanceList')->pluck('name')->all())->toBe([$current->name])
        ->and($page->viewData('absentCount'))->toBe(1)
        ->and($page->viewData('pendingLeaves'))->toHaveCount(1);
});

test('rejecting a request that was cancelled meanwhile shows a message, not an error', function () {
    $manager = landingUser(UserRole::Manager);
    $report = landingUser(UserRole::Employee, [], ['manager_id' => $manager->id]);
    $type = LeaveType::create(['name' => 'Casual', 'annual_allocation_days' => 0]);
    $leave = LeaveRequest::create([
        'employee_id' => $report->employee->id, 'leave_type_id' => $type->id,
        'start_date' => today()->addDays(5)->toDateString(), 'end_date' => today()->addDays(5)->toDateString(),
        'days' => 1, 'reason' => 'Family event', 'status' => 'pending',
    ]);

    $page = Livewire::actingAs($manager)->test(ManagerDashboard::class)->call('openRejectModal', $leave->id);
    $leave->update(['status' => 'cancelled']);

    $page->set('rejectComment', 'Team is short that week')
        ->call('quickRejectLeave')
        ->assertOk()
        ->assertSet('showRejectModal', false);

    expect($leave->fresh()->status)->toBe('cancelled');
});
