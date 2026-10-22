<?php

use App\Enums\UserRole;
use App\Livewire\Help\EmployeeGuide;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\EmployeeMenu;
use App\Services\Help\EmployeeGuide as EmployeeGuideContent;
use App\Services\Help\RouteAccess;
use App\Services\Navigation\Sidebar;
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

/**
 * Role matrix: the guide is open to every signed-in role, and every link on it
 * must open for the reader (never a 403 or an error) — a link they cannot use
 * is hidden, not shown.
 */
dataset('guide roles', ['super_admin', 'hr_admin', 'director', 'department_head', 'manager', 'finance', 'coordinator', 'employee']);

function guideRoleUser(string $slug): User
{
    $department = Department::factory()->create();
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active']);

    if ($slug === 'department_head') {
        $department->update(['head_id' => $user->id]);
    }

    return $user->fresh();
}

/** @return array<int, string> same-site links on the rendered page, anchors and assets excluded */
function guidePageLinks(string $html): array
{
    preg_match_all('/<a\s[^>]*href="([^"#]+)"/i', $html, $matches);
    $host = parse_url(config('app.url'), PHP_URL_HOST);

    return collect($matches[1])
        ->map(fn (string $href): string => html_entity_decode($href))
        ->filter(fn (string $href): bool => str_starts_with($href, '/') || str_contains($href, (string) $host) || str_contains($href, 'localhost'))
        ->map(fn (string $href): string => parse_url($href, PHP_URL_PATH) ?: '/')
        ->reject(fn (string $path): bool => str_starts_with($path, '/images') || str_starts_with($path, '/build') || str_starts_with($path, '/logout'))
        ->unique()
        ->values()
        ->all();
}

test('every role can open the guide and every link on it opens for that role', function (string $slug) {
    $user = guideRoleUser($slug);

    $html = $this->withoutVite()->actingAs($user)->get(route('help.employee-guide'))->assertOk()->getContent();
    $links = guidePageLinks($html);

    expect($links)->not->toBeEmpty();

    foreach ($links as $path) {
        $status = $this->actingAs($user)->get($path)->getStatusCode();

        expect($status)->not->toBe(403, "{$slug}: guide links to {$path}, which returns 403")
            ->and($status)->not->toBe(404, "{$slug}: guide links to {$path}, which returns 404")
            ->and($status)->toBeLessThan(500, "{$slug}: guide link {$path} returns {$status}");
    }
})->with('guide roles');

test('assets stay out of the guide for roles that cannot open the assets register', function (string $slug) {
    $html = $this->withoutVite()->actingAs(guideRoleUser($slug))->get(route('help.employee-guide'))->getContent();

    expect($html)->not->toContain(route('operations.assets', absolute: false));
})->with(['employee', 'coordinator', 'manager', 'finance', 'department_head']);

test('the guide names every item in the employee sidebar', function () {
    $user = guideRoleUser('employee');
    $this->actingAs($user);

    // Plain employees get EmployeeMenu (the layout's $pureEmployee branch).
    expect(app(Sidebar::class)->isSelfServiceOnly($user))->toBeTrue();

    $labels = collect(app(EmployeeMenu::class)->visible())->pluck('label')->unique();
    $guideText = json_encode(app(EmployeeGuideContent::class)->sections($user), JSON_UNESCAPED_UNICODE);

    $missing = $labels->reject(fn (string $label): bool => str_contains($guideText, $label))->values()->all();

    expect($missing)->toBe([], 'The employee sidebar shows items the guide never mentions: '.implode(', ', $missing));
});

test('the guide explains the My Work menu that managers and HR see', function () {
    $user = guideRoleUser('manager');
    $this->actingAs($user);

    expect(app(Sidebar::class)->isSelfServiceOnly($user))->toBeFalse();

    $guideText = json_encode(app(EmployeeGuideContent::class)->sections($user), JSON_UNESCAPED_UNICODE);

    expect($guideText)->toContain('My Work');
});
