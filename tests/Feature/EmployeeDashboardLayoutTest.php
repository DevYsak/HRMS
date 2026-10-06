<?php

use App\Enums\UserRole;
use App\Livewire\Dashboard;
use App\Models\Employee;
use App\Models\ProfileFieldSetting;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\EmployeeDashboardService;
use App\Services\ModuleFeatureService;
use App\Services\Profile\ProfileFieldRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * The compact employee dashboard: the six-figure KPI row, a profile
 * completion card, upcoming holidays on the employee's own calendar, and no
 * trace of a module switched off in Settings → Modules.
 */
beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00'));
});

function edlEmployee(): User
{
    $user = User::factory()->create(['role' => UserRole::Employee]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'joining_date' => '2024-01-08', 'holiday_calendar' => 'UK']);

    return $user->fresh();
}

test('the KPI row is Attendance, Available Leave, Late Arrivals, OT, Pending Requests and Payslip', function () {
    $labels = collect(app(EmployeeDashboardService::class)->build(edlEmployee())['kpis'])->pluck('label')->all();

    expect($labels)->toBe(['Attendance', 'Available Leave', 'Late Arrivals', 'OT', 'Pending Requests', 'Payslip']);
});

test('with payroll switched off, the payslip tile and card leave no trace', function () {
    $user = edlEmployee();
    app(ModuleFeatureService::class)->setPayroll(false, User::factory()->create(['role' => UserRole::SuperAdmin]));

    $data = app(EmployeeDashboardService::class)->build($user);

    expect(collect($data['kpis'])->pluck('label')->all())->not->toContain('Payslip')
        ->and($data['kpis'])->toHaveCount(5)
        ->and($data['payroll'])->toBeNull();

    Livewire::actingAs($user)->test(Dashboard::class)
        ->assertDontSee('No payslip generated yet.')
        ->assertDontSee('Download payslip');
});

test('the profile completion card shows the gap and leaves once complete', function () {
    $user = edlEmployee();
    $user->employee->update(['phone' => null]);

    Livewire::actingAs($user)->test(Dashboard::class)
        ->assertSee('Profile completion')
        ->assertSee('Complete profile');

    // Nothing required any more → 100% → the card is gone.
    foreach (ProfileFieldRegistry::selfServiceKeys() as $key) {
        ProfileFieldSetting::updateOrCreate(['field_key' => $key], ['requirement' => ProfileFieldSetting::OPTIONAL]);
    }

    Livewire::actingAs($user)->test(Dashboard::class)->assertDontSee('Profile completion');
});

test('upcoming holidays come from the employee\'s own calendar', function () {
    $user = edlEmployee();
    PublicHoliday::create(['name' => 'Founders Day', 'date' => '2026-10-21', 'country' => 'UK']);
    PublicHoliday::create(['name' => 'Diwali Holiday', 'date' => '2026-10-20', 'country' => 'IN']);

    Livewire::actingAs($user)->test(Dashboard::class)
        ->assertSee('Upcoming holidays')
        ->assertSee('Founders Day')
        ->assertDontSee('Diwali Holiday');
});

test('cards are top-aligned and never stretched to fill a row', function () {
    $html = Livewire::actingAs(edlEmployee())->test(Dashboard::class)->html();

    expect($html)->toContain('xl:items-start')
        ->not->toContain('flex flex-1 flex-col px-5 pb-5');
});
