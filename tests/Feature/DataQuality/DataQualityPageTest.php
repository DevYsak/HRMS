<?php

use App\Livewire\Employees\DataQuality;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Services\Navigation\Sidebar;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** People → Data Quality: HR and Super Admin, scoped, read-only. */
function dqpUser(string $slug, ?Department $department = null, array $attributes = []): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id, ...$attributes]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => ($department ?? Department::factory()->create())->id, 'status' => 'active', 'manager_id' => null]);

    return $user->fresh();
}

beforeEach(function () {
    $this->ops = Department::factory()->create(['name' => 'DQP Operations']);
    $this->sales = Department::factory()->create(['name' => 'DQP Sales']);
    // One employee per department with no manager: an issue in each.
    $this->opsGap = dqpUser('employee', $this->ops, ['name' => 'Olive Opsgap']);
    $this->salesGap = dqpUser('employee', $this->sales, ['name' => 'Sam Salesgap']);
});

test('Super Admin and HR open every tab and find it under People', function (string $slug) {
    $user = dqpUser($slug);

    foreach (array_keys(DataQuality::TABS) as $tab) {
        $this->actingAs($user)->get(route('employees.data-quality', ['tab' => $tab]))->assertOk();
    }
    $this->actingAs($user)->get(route('employees.data-quality'))
        ->assertSee('Olive Opsgap')->assertSee('Sam Salesgap')->assertSee('Active employee without reporting manager');
    $this->actingAs($user)->get(route('employees.data-quality', ['tab' => 'roles']))->assertSee('Per-user overrides');

    $people = collect(app(Sidebar::class)->groups($user))->firstWhere('key', 'people');
    expect(collect($people['items'])->pluck('label'))->toContain('Data Quality');
})->with(['super_admin', 'hr_admin']);

test('roles without the permission cannot open it or see it in the menu', function (string $slug) {
    $user = dqpUser($slug);

    $this->actingAs($user)->get(route('employees.data-quality'))->assertForbidden();
    $labels = collect(app(Sidebar::class)->groups($user))->flatMap(fn ($g) => collect($g['items'])->pluck('label'));
    expect($labels)->not->toContain('Data Quality');
})->with(['director', 'department_head', 'manager', 'finance', 'coordinator', 'employee']);

test('a department-scoped HR admin sees only their department, and no company-wide role drift', function () {
    $hr = dqpUser('hr_admin', $this->ops, ['scope_departments' => [$this->ops->id]]);

    $this->actingAs($hr)->get(route('employees.data-quality'))
        ->assertOk()->assertSee('Olive Opsgap')->assertDontSee('Sam Salesgap')->assertSee('Your departments only');
    $this->actingAs($hr)->get(route('employees.data-quality', ['tab' => 'structure']))
        ->assertSee('Olive Opsgap')->assertDontSee('Sam Salesgap');
    $this->actingAs($hr)->get(route('employees.data-quality', ['tab' => 'departments']))
        ->assertSee('DQP Operations')->assertDontSee('DQP Sales');

    Livewire::actingAs($hr)->test(DataQuality::class)->call('setTab', 'roles')->assertSet('tab', 'issues')->assertDontSee('Per-user overrides');
});

test('filters narrow the issue list', function () {
    $admin = dqpUser('super_admin');

    Livewire::actingAs($admin)->test(DataQuality::class)
        ->set('department', (string) $this->sales->id)->assertSee('Sam Salesgap')->assertDontSee('Olive Opsgap')
        ->set('department', '')->set('employee', 'olive')->assertSee('Olive Opsgap')->assertDontSee('Sam Salesgap')
        ->set('employee', '')->set('check', 'no_manager')->set('severity', 'critical')->assertSee('No issues match');
});

test('issues link to the record to fix by hand; the page itself changes nothing', function () {
    $hr = dqpUser('hr_admin');
    $tables = ['users', 'employees', 'departments', 'roles', 'role_permission', 'user_permission_overrides'];
    $before = collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();

    $page = $this->actingAs($hr)->get(route('employees.data-quality'))->assertOk();
    $page->assertSee(route('employees.edit', $this->opsGap->employee), false)->assertSee('Open employee');
    foreach (array_keys(DataQuality::TABS) as $tab) {
        $this->actingAs($hr)->get(route('employees.data-quality', ['tab' => $tab]))->assertOk();
    }

    expect(collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all())->toBe($before)
        ->and($this->opsGap->employee->fresh()->manager_id)->toBeNull();
});
