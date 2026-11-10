<?php

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Features\SupportRedirects\SupportRedirects;

/*
 * Opens every page a signed-in user can reach — each parameterless GET page
 * plus the main employee, leave and department detail pages — as each role,
 * and fails on any server error. A refused (403) or redirected page is fine:
 * that role simply cannot open it. Livewire actions are covered by their own
 * feature tests; this catches pages that crash while rendering.
 */

/**
 * Parameterless GET pages behind sign-in, minus the ones that are not pages
 * (device and API endpoints) or that switch the session.
 *
 * @return array<int, string>
 */
function allPagesSmokeUris(): array
{
    $skip = ['impersonate/stop'];

    return collect(Route::getRoutes()->getRoutes())
        ->filter(function (RoutingRoute $route) use ($skip): bool {
            $middleware = $route->gatherMiddleware();

            return in_array('GET', $route->methods(), true)
                && ! str_contains($route->uri(), '{')
                && in_array('web', $middleware, true)
                && collect($middleware)->contains(fn ($name) => is_string($name) && Str::startsWith($name, 'auth'))
                && ! in_array($route->uri(), $skip, true);
        })
        ->map(fn (RoutingRoute $route): string => '/'.ltrim($route->uri(), '/'))
        ->unique()
        ->values()
        ->all();
}

/**
 * Requests each URI and returns the server errors found and how many pages opened.
 *
 * @param  array<int, string>  $uris
 * @return array{failures: array<int, string>, opened: int}
 */
function allPagesSmokeCrawl(User $user, array $uris): array
{
    $failures = [];
    $opened = 0;

    // Every browser request gets a fresh application. Here one app serves the
    // whole crawl, and a Livewire page refused (403) while mounting never puts
    // Laravel's redirector back — so put it back before each page, as a new
    // request would have it.
    $redirector = app('redirect');

    foreach ($uris as $uri) {
        app()->instance('redirect', $redirector);
        Redirect::clearResolvedInstance();
        SupportRedirects::$redirectorCacheStack = [];

        try {
            $response = test()->actingAs($user)->get($uri);
        } catch (Throwable $e) {
            $failures[] = $uri.' → '.class_basename($e).': '.Str::limit($e->getMessage(), 240).' @ '.basename($e->getFile()).':'.$e->getLine();

            continue;
        }

        $status = $response->getStatusCode();
        if ($status === 200) {
            $opened++;
        }

        if ($status >= 500) {
            $e = $response->exception;
            $failures[] = $uri.' → '.$status.($e ? ' '.class_basename($e).': '.Str::limit($e->getMessage(), 240).' @ '.basename($e->getFile()).':'.$e->getLine() : '');
        }
    }

    return ['failures' => $failures, 'opened' => $opened];
}

test('every page opens without a server error for each role', function (UserRole $role) {
    $this->withoutVite();

    $department = Department::factory()->create();
    $user = User::factory()->create(['role' => $role]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'department_id' => $department->id, 'manager_id' => null]);

    // A team member reporting to this user, with a pending leave request, so
    // team, approval and detail pages have something to show.
    $member = Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active', 'department_id' => $department->id, 'manager_id' => $user->id,
    ]);
    $type = LeaveType::create(['name' => 'Annual Leave', 'code' => 'ANL', 'category' => 'annual', 'is_paid' => true,
        'allow_paid_request' => true, 'color' => '#F97316']);
    $leave = LeaveRequest::create(['employee_id' => $member->id, 'leave_type_id' => $type->id,
        'start_date' => now()->addWeek()->toDateString(), 'end_date' => now()->addWeek()->toDateString(),
        'days' => 1, 'reason' => 'Family event', 'status' => 'pending']);

    $details = [
        route('employees.edit', $member, false),
        route('employees.profile', $member, false),
        route('employees.360', $member, false),
        route('employees.probation', $member, false),
        route('employees.onboarding', $member, false),
        route('employees.offboarding', $member, false),
        route('employees.finance-profile', $member, false),
        route('time-off.leave-management.employee', $member, false),
        route('time-off.leave-requests.show', $leave, false),
        route('departments.show', $department, false),
    ];

    $uris = allPagesSmokeUris();
    expect(count($uris))->toBeGreaterThan(100);

    $result = allPagesSmokeCrawl($user, [...$uris, ...$details]);

    expect($result['failures'])->toBe([])
        ->and($result['opened'])->toBeGreaterThan(10);
})->with(UserRole::cases())->group('smoke');

test('opening the combined print or the bulk payslip download with nothing selected sends the user back with a message, not a server error', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $employee->id, 'status' => 'active']);

    $this->withoutVite()->actingAs($employee)
        ->from(route('payroll.payslips'))
        ->get(route('payroll.payslips.print-combined'))
        ->assertRedirect(route('payroll.payslips'))
        ->assertSessionHasErrors(['ids' => 'Select at least one payslip to print.']);

    $finance = User::factory()->create(['role' => UserRole::Finance]);
    Employee::factory()->create(['user_id' => $finance->id, 'status' => 'active']);

    $this->actingAs($finance)
        ->from(route('payroll.payslips'))
        ->get(route('payroll.payslips.download-bulk'))
        ->assertRedirect(route('payroll.payslips'))
        ->assertSessionHasErrors(['ids' => 'Select at least one payslip to download.']);
})->group('smoke');
