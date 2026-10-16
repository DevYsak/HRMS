<?php

use App\Models\Employee;
use App\Models\ExitRecord;
use App\Models\Incentive;
use App\Models\OnboardingTask;
use App\Models\PipRecord;
use App\Models\Reimbursement;
use App\Models\Role;
use App\Models\User;
use App\Models\WarningLetter;
use App\Notifications\IncentiveNotification;
use App\Notifications\OffboardingDetailsNotification;
use App\Notifications\OnboardingCompletedNotification;
use App\Notifications\OnboardingTaskOverdueNotification;
use App\Notifications\PipWeeklyReviewDueNotification;
use App\Notifications\ReimbursementNotification;
use App\Notifications\WarningEscalatedNotification;
use App\Services\Help\RouteAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/**
 * Phase 4 · Notifications: one notification often reaches the employee and
 * HR. Each copy links to a page that recipient can open — the employee's
 * copy used to point at HR-only pages (403).
 */
function nlUser(string $slug, string $name): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['name' => $name, 'role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'manager_id' => null]);

    return $user->fresh();
}

/** The route a stored link points at, and whether the recipient may open it. */
function nlOpens(User $user, string $url): bool
{
    $route = Route::getRoutes()->match(Request::create($url));

    return app(RouteAccess::class)->allows($user, $route->getName());
}

beforeEach(function () {
    $this->employee = nlUser('employee', 'Eve Employee');
    $this->hr = nlUser('hr_admin', 'Hana HR');
    $this->manager = nlUser('manager', 'Mia Manager');
    $e = $this->employee->employee->setRelation('user', $this->employee);

    $this->notifications = [
        'incentive' => new IncentiveNotification(new Incentive(['title' => 'Q3 bonus', 'amount' => 5000, 'status' => 'approved'])),
        'reimbursement' => new ReimbursementNotification(new Reimbursement(['title' => 'Taxi', 'amount' => 300, 'status' => 'rejected'])),
        'warning_escalated' => new WarningEscalatedNotification((new WarningLetter(['reason' => 'Lateness']))->setRelation('employee', $e)),
        'pip_weekly' => new PipWeeklyReviewDueNotification((new PipRecord(['end_date' => Carbon::parse('2026-12-31')]))->setRelation('employee', $e)),
        'onboarding_completed' => new OnboardingCompletedNotification($e),
        'onboarding_overdue' => new OnboardingTaskOverdueNotification(new OnboardingTask(['title' => 'Sign NDA']), $e),
        'offboarding' => new OffboardingDetailsNotification(new ExitRecord(['employee_id' => $e->id, 'last_working_day' => '2026-12-31', 'exit_type' => 'resignation'])),
    ];
});

test('the employee copy of every notification opens for the employee', function () {
    foreach ($this->notifications as $key => $notification) {
        $url = $notification->toArray($this->employee)['url'];
        expect(nlOpens($this->employee, $url))->toBeTrue("{$key}: {$url}");
    }
});

test('HR still lands on the HR pages', function () {
    $expected = [
        'incentive' => 'payroll.incentives',
        'reimbursement' => 'payroll.reimbursements',
        'warning_escalated' => 'performance.warnings.manage',
        'pip_weekly' => 'performance.pip.manage',
        'onboarding_completed' => 'employees.onboarding',
        'onboarding_overdue' => 'employees.onboarding',
        'offboarding' => 'employees.offboarding',
    ];

    foreach ($this->notifications as $key => $notification) {
        $url = $notification->toArray($this->hr)['url'];
        expect(Route::getRoutes()->match(Request::create($url))->getName())->toBe($expected[$key], $key);
    }
});

test('a manager without the HR permission never gets an HR-only link', function () {
    foreach (['onboarding_overdue', 'onboarding_completed', 'offboarding', 'incentive'] as $key) {
        $url = $this->notifications[$key]->toArray($this->manager)['url'];
        expect(nlOpens($this->manager, $url))->toBeTrue("{$key}: {$url}");
    }
});

test('the employee copy speaks to the employee', function () {
    expect($this->notifications['warning_escalated']->toArray($this->employee)['body'])->toStartWith('Your warning has been escalated')
        ->and($this->notifications['warning_escalated']->toArray($this->hr)['body'])->toContain('The warning for Eve Employee')
        ->and($this->notifications['pip_weekly']->toArray($this->employee)['body'])->toContain('your performance improvement plan');
});
