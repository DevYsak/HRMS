<?php

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Livewire\Settings\MenuSettings;
use App\Models\Employee;
use App\Models\MenuSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\EmployeeMenu;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

function menuAdmin(): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $role = Role::where('slug', 'super_admin')->firstOrFail();

    return User::factory()->create(['role' => UserRole::SuperAdmin, 'role_id' => $role->id]);
}

test('the employee menu is fully visible by default (fail-open)', function () {
    $items = app(EmployeeMenu::class)->visible();

    // 12 since My Onboarding joined the catalog — employees previously had no
    // route to their own onboarding tasks at all.
    expect($items)->toHaveCount(12);
    expect(collect($items)->pluck('key'))->toContain('dashboard', 'attendance', 'onboarding', 'inbox');
});

test('disabling an item hides it from the visible menu', function () {
    MenuSetting::create(['key' => 'attendance', 'is_enabled' => false, 'sort_order' => 2]);

    $keys = collect(app(EmployeeMenu::class)->visible())->pluck('key');

    expect($keys)->not->toContain('attendance');
    expect($keys)->toContain('dashboard');
});

test('a label override is applied', function () {
    MenuSetting::create(['key' => 'leave', 'label' => 'Time Off', 'is_enabled' => true, 'sort_order' => 3]);

    $leave = collect(app(EmployeeMenu::class)->visible())->firstWhere('key', 'leave');

    expect($leave['label'])->toBe('Time Off');
});

test('sort order reorders the menu', function () {
    MenuSetting::create(['key' => 'dashboard', 'is_enabled' => true, 'sort_order' => 100]);

    $keys = collect(app(EmployeeMenu::class)->visible())->pluck('key')->all();

    expect(end($keys))->toBe('dashboard');
});

test('admin can save menu settings from the UI', function () {
    $admin = menuAdmin();

    Livewire::actingAs($admin)->test(MenuSettings::class)
        ->assertSee('Sidebar Menu')
        ->set('items.2.enabled', false)   // index 2 == attendance in default order
        ->call('save');

    expect(MenuSetting::where('key', 'attendance')->where('is_enabled', false)->exists())->toBeTrue();
});

test('moveDown reorders the working list', function () {
    $admin = menuAdmin();

    $component = Livewire::actingAs($admin)->test(MenuSettings::class);
    $firstKey = $component->get('items')[0]['key'];

    $component->call('moveDown', 0);

    expect($component->get('items')[1]['key'])->toBe($firstKey);
});

test('a non-admin cannot open sidebar menu settings', function () {
    $employee = User::factory()->create(['role' => UserRole::Employee]);

    Livewire::actingAs($employee)->test(MenuSettings::class)->assertForbidden();
});

test('the employee sidebar page renders without error', function () {
    $employee = Employee::factory()->create(['status' => EmployeeStatus::Active->value]);
    $user = $employee->user;
    $user->forceFill(['role' => UserRole::Employee, 'email_verified_at' => now()])->save();

    $this->actingAs($user)->get('/')->assertOk();
});

test('the workspace nav does not show HR Overview alongside Dashboard for HR', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $role = Role::where('slug', 'hr_admin')->firstOrFail();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin, 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $hr->id, 'status' => EmployeeStatus::Active]);

    $response = $this->actingAs($hr)->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('Dashboard')
        ->assertDontSee('HR Overview');
});

test('the HR Overview route stays reachable even though it is unlinked', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $role = Role::where('slug', 'hr_admin')->firstOrFail();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin, 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $hr->id, 'status' => EmployeeStatus::Active]);

    $this->actingAs($hr)->get(route('dashboard.hr-admin'))->assertOk();
});
