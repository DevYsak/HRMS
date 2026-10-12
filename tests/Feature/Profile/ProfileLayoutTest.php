<?php

use App\Enums\UserRole;
use App\Livewire\Employees\EmployeeEdit;
use App\Livewire\Profile\MyProfile;
use App\Models\Employee;
use App\Models\User;
use App\Services\Profile\ProfileFieldRegistry;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/**
 * Layout of the two profile pages: cards follow their content (no stretched
 * blank panels), every field shows who owns it, and the employee record keeps
 * three shortcuts. Presentation only — tiers come from ProfileFieldRegistry.
 */
function layoutEmployee(): Employee
{
    $user = User::factory()->create(['name' => 'Layout Person', 'role' => UserRole::Employee]);

    return Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
}

test('the employee record shortcuts are Profile, Attendance and Documents only', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));
    $employee = layoutEmployee();

    $html = Livewire::test(EmployeeEdit::class, ['employee' => $employee])->html();
    $shortcuts = str($html)->after('data-employee-shortcuts')->before('</aside>')->toString();

    expect($shortcuts)->toContain('Profile', 'Attendance', 'Documents')
        ->and($shortcuts)->not->toContain('Activity');
});

test('the Activity panel itself is still reachable on the employee record', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::HrAdmin]));

    Livewire::test(EmployeeEdit::class, ['employee' => layoutEmployee()])
        ->call('setTab', 'Activity')
        ->assertSet('activeTab', 'Activity')
        ->assertSee('Activity Log');
});

test('the Personal tab renders Identity', function () {
    $employee = layoutEmployee();

    Livewire::actingAs($employee->user)->test(MyProfile::class)
        ->call('setTab', 'personal')
        ->assertSee('Identity')
        ->assertSee(ProfileFieldRegistry::label('date_of_birth'))
        ->assertSee('Bank & statutory')
        ->assertDontSee('Bank &amp;amp; statutory', false);
});

test('the Employment tab renders every employment field in a two-column grid', function () {
    $employee = layoutEmployee();

    $component = Livewire::actingAs($employee->user)->test(MyProfile::class)->call('setTab', 'employment');

    foreach (array_keys(ProfileFieldRegistry::group('employment')) as $field) {
        $component->assertSeeHtml('data-profile-field="'.$field.'"');
    }

    $component->assertSeeHtml('xl:grid-cols-2');
});

test('the Finish your profile list shows the missing required fields', function () {
    $employee = layoutEmployee();
    $component = Livewire::actingAs($employee->user)->test(MyProfile::class);
    $missing = $component->viewData('completion')['missing'];

    expect($missing)->not->toBeEmpty();

    $component->assertSeeHtml('data-finish-profile')
        ->assertSee(count($missing).' required');

    foreach (array_slice($missing, 0, 6) as $gap) {
        $component->assertSee($gap['label']);
    }
});

test('every field row shows the ownership its registry tier gives it', function () {
    $employee = layoutEmployee();

    $html = Livewire::actingAs($employee->user)->test(MyProfile::class)->call('setTab', 'personal')->html();

    foreach (['identity', 'contact', 'financial'] as $group) {
        foreach (array_keys(ProfileFieldRegistry::group($group)) as $field) {
            if ((ProfileFieldRegistry::get($field)['type'] ?? null) === 'image') {
                continue;
            }

            $row = str($html)->after('data-profile-field="'.$field.'"')->before('data-profile-field=')->toString();
            $expected = match (true) {
                ProfileFieldRegistry::tier($field) === ProfileFieldRegistry::TIER_LOCKED || ProfileFieldRegistry::isHrOnly($field) => 'Managed by HR',
                ProfileFieldRegistry::tier($field) === ProfileFieldRegistry::TIER_EDITABLE => 'You can edit',
                default => 'Needs HR approval',
            };

            expect(str_contains($row, $expected))->toBeTrue("{$field} should show '{$expected}'");
        }
    }
});

test('profile cards follow their content height instead of stretching', function () {
    $card = File::get(resource_path('views/components/employee/section-card.blade.php'));
    $page = File::get(resource_path('views/livewire/profile/my-profile.blade.php'));
    $fieldRow = File::get(resource_path('views/components/profile/field.blade.php'));

    expect($card)->not->toContain('h-full')->not->toContain('flex-1')
        ->and($page)->toContain('items-start')->not->toMatch('/\bmin-h-\[|\bh-\[\d/')
        ->and($fieldRow)->not->toMatch('/\bmin-h-|\bh-\[\d/');
});
