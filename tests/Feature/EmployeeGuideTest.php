<?php

use App\Enums\UserRole;
use App\Livewire\Help\EmployeeGuide;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeMenu;
use App\Services\Help\EmployeeGuide as EmployeeGuideContent;
use App\Services\Help\RouteAccess;
use Livewire\Livewire;

/**
 * The in-app Employee Guide (/help/employee-guide): open to every signed-in
 * user, but it must only point readers at pages their own role can open.
 */
function guideEmployee(): User
{
    $user = User::factory()->create(['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);

    return $user;
}

test('guests are sent to the login page', function () {
    $this->get(route('help.employee-guide'))->assertRedirect(route('login'));
});

test('an employee can open the guide', function () {
    $this->withoutVite()
        ->actingAs(guideEmployee())
        ->get(route('help.employee-guide'))
        ->assertOk()
        ->assertSee('Employee Guide')
        ->assertSee('Applying for leave and tracking it')
        ->assertSee('Correcting your attendance (regularisation)')
        ->assertSee('Frequently asked questions');
});

test('deep links only point at pages the reader can open', function () {
    $employee = guideEmployee();
    $sections = app(EmployeeGuideContent::class)->sections($employee);
    $linkedRoutes = collect($sections)->flatMap(fn (array $section) => collect($section['links'])->pluck('route'));

    expect($linkedRoutes)->toContain('time-off.my', 'attendance.my', 'payroll.payslips', 'profile.me');

    $access = app(RouteAccess::class);
    $linkedRoutes->each(fn (string $route) => expect($access->allows($employee, $route))->toBeTrue());
});

test('route access mirrors the route guards', function () {
    $employee = guideEmployee();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $access = app(RouteAccess::class);

    expect($access->allows($employee, 'time-off.my'))->toBeTrue()
        ->and($access->allows($employee, 'operations.assets'))->toBeFalse()          // role:manage-employees
        ->and($access->allows($employee, 'time-off.regularisation'))->toBeFalse()    // can:view_leave_regularisation
        ->and($access->allows($employee, 'no.such.route'))->toBeFalse()
        ->and($access->allows($admin, 'operations.assets'))->toBeTrue();

    $this->actingAs($employee)->get(route('operations.assets'))->assertForbidden();
});

test('the can / cannot table reflects the reader\'s real permissions', function () {
    $allowed = collect(app(EmployeeGuideContent::class)->capabilities(guideEmployee()))->pluck('allowed', 'feature');

    expect($allowed['Leave'])->toBeTrue()
        ->and($allowed['Payslips'])->toBeTrue()
        ->and($allowed['My profile'])->toBeTrue()
        ->and($allowed['Company settings'])->toBeFalse()
        ->and($allowed['Roles & permissions'])->toBeFalse()
        ->and($allowed['Payroll administration'])->toBeFalse()
        ->and($allowed["Other employees' records"])->toBeFalse()
        ->and($allowed['Company assets register'])->toBeFalse();
});

test('the guide lists profile fields by the tier the profile page enforces', function () {
    $tiers = app(EmployeeGuideContent::class)->profileTiers();

    expect($tiers['editable'])->toContain('Phone', 'Emergency contact')
        ->and($tiers['approval'])->toContain('Residential address', 'PAN', 'Aadhaar')
        ->and($tiers['locked'])->toContain('Work email', 'Department', 'Reporting manager');
});

test('screenshots and marker legends come from the capture manifest', function () {
    $this->withoutVite()->actingAs(guideEmployee());

    $shots = app(EmployeeGuideContent::class)->shots();

    if ($shots === []) {
        $this->markTestSkipped('Screenshots not generated (npm run guide:screenshots).');
    }

    Livewire::test(EmployeeGuide::class)
        ->assertSee('images/employee-guide/annotated/leave-top.png', false)
        ->assertSee($shots['leave-top']['markers'][0]['label']);
});

test('every employee can reach the guide from the sidebar', function () {
    $help = collect(app(EmployeeMenu::class)->visible())->firstWhere('key', 'help');

    expect($help['route'])->toBe('help.employee-guide');
});

test('the demo account command refuses to run outside local or testing', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('guide:demo-employee')->assertFailed();

    expect(User::where('email', 'guide.employee@example.com')->exists())->toBeFalse();
});

test('the demo account command creates a plain employee with fictional data and cleans up', function () {
    $this->artisan('guide:demo-employee')->assertSuccessful();

    $user = User::where('email', 'guide.employee@example.com')->firstOrFail();

    expect($user->role)->toBe(UserRole::Employee)
        ->and($user->hasPermission('manage_employees'))->toBeFalse()
        ->and($user->employee->phone)->toBe('+91 90000 00000');

    $this->artisan('guide:demo-employee')->assertSuccessful();
    expect(User::where('email', 'guide.employee@example.com')->count())->toBe(1);

    $this->artisan('guide:demo-employee', ['--remove' => true])->assertSuccessful();
    expect(User::withTrashed()->whereIn('email', ['guide.employee@example.com', 'guide.manager@example.com'])->exists())->toBeFalse()
        ->and(Employee::withTrashed()->where('employee_id', 'GUIDE-0001')->exists())->toBeFalse();
});
