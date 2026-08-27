<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\TimeOffSettings;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * The Leave Settings screen states the policy, not a yes/no.
 *
 * "CF: Yes" cannot say whether carrying needs approval or how much may carry,
 * and the number it used to print came from the leave type — which no longer
 * decides anything. HR had to open each record to find out what was actually
 * configured, and what they found there could be wrong.
 */
function lsdAdmin(): User
{
    return User::factory()->create(['role' => UserRole::HrAdmin]);
}

/**
 * The default policy, set to a given ceiling.
 *
 * Updated rather than created: the canonical migration already installs UK
 * Standard as the default, and a second default would mean the screen reads
 * whichever the database returns first. There is one default by definition.
 */
function lsdPolicy(?float $maxCarryOver): LeavePolicy
{
    $policy = LeavePolicy::where('is_default', true)->first();

    if ($policy) {
        $policy->update(['max_carry_over_days' => $maxCarryOver, 'statutory_weeks' => 5.60]);

        return $policy->fresh();
    }

    return LeavePolicy::create([
        'name' => 'Policy '.Str::random(5),
        'statutory_weeks' => 5.60,
        'contractual_additional_weeks' => 0,
        'bank_holiday_treatment' => 'additional',
        'max_carry_over_days' => $maxCarryOver,
        'irregular_accrual_rate' => 0.1207,
        'is_default' => true,
        'is_active' => true,
    ]);
}

/**
 * The canonical migration already seeds Annual Leave, so a test that wants to
 * describe AL must configure the existing record rather than insert a second
 * one — leave_types.code is uniquely indexed, and a duplicate is exactly what
 * the canonical configuration exists to prevent.
 */
function lsdType(array $attributes = []): LeaveType
{
    $attributes = array_merge([
        'name' => 'Type '.Str::random(4),
        'code' => 'T'.strtoupper(Str::random(3)),
        'category' => 'annual',
    ], $attributes);

    $existing = LeaveType::withTrashed()->where('code', $attributes['code'])->first();

    if ($existing) {
        $existing->restore();
        $existing->update($attributes);

        return $existing->fresh();
    }

    return LeaveType::create($attributes);
}

test('annual leave shows a policy-driven entitlement, not a fixed number', function () {
    lsdPolicy(null);
    lsdType(['name' => 'Annual Leave', 'code' => 'AL', 'category' => 'annual', 'annual_allocation_days' => null, 'allow_carry_forward' => true]);

    Livewire::actingAs(lsdAdmin())->test(TimeOffSettings::class)
        ->assertOk()
        ->assertSee('Policy / working pattern')
        ->assertSee('5.6 weeks x verified working days');
});

test('carry forward reads as approval and ceiling, not yes', function () {
    lsdPolicy(null);
    lsdType(['name' => 'Annual Leave', 'code' => 'AL', 'annual_allocation_days' => null, 'allow_carry_forward' => true]);

    Livewire::actingAs(lsdAdmin())->test(TimeOffSettings::class)
        ->assertOk()
        ->assertSee('HR approval · Unlimited')
        ->assertDontSee('CF: Yes');
});

test('a numeric policy cap is stated in days', function () {
    lsdPolicy(10);
    lsdType(['name' => 'Annual Leave', 'code' => 'AL', 'annual_allocation_days' => null, 'allow_carry_forward' => true]);

    Livewire::actingAs(lsdAdmin())->test(TimeOffSettings::class)
        ->assertOk()
        ->assertSee('HR approval · Max 10 days');
});

test('a policy cap of zero says no carry forward is permitted', function () {
    lsdPolicy(0);
    lsdType(['name' => 'Annual Leave', 'code' => 'AL', 'annual_allocation_days' => null, 'allow_carry_forward' => true]);

    Livewire::actingAs(lsdAdmin())->test(TimeOffSettings::class)
        ->assertOk()
        ->assertSee('None permitted by policy');
});

test('a type that does not carry forward says so plainly', function () {
    lsdPolicy(null);
    lsdType(['name' => 'Sick Leave', 'code' => 'SL', 'annual_allocation_days' => 6, 'allow_carry_forward' => false]);

    Livewire::actingAs(lsdAdmin())->test(TimeOffSettings::class)
        ->assertOk()
        ->assertSee('Not carried forward')
        ->assertSee('6 days per year');
});

test('comp off shows as earned rather than allocated', function () {
    lsdPolicy(null);
    lsdType(['name' => 'Comp Off', 'code' => 'CO', 'category' => 'comp_off', 'annual_allocation_days' => null, 'allow_carry_forward' => true]);

    Livewire::actingAs(lsdAdmin())->test(TimeOffSettings::class)
        ->assertOk()
        ->assertSee('Earned — no fixed annual allocation');
});

test('unpaid leave says payroll deducts', function () {
    lsdPolicy(null);
    lsdType(['name' => 'Leave Without Pay', 'code' => 'LWP', 'category' => 'lwp', 'is_paid' => false, 'payment_mode' => 'unpaid']);

    Livewire::actingAs(lsdAdmin())->test(TimeOffSettings::class)
        ->assertOk()
        ->assertSee('Unpaid — payroll deducts')
        ->assertSee('No annual allocation');
});

test('work from home is labelled as an attendance mode', function () {
    lsdPolicy(null);
    lsdType(['name' => 'Work From Home', 'code' => 'WFH', 'category' => 'wfh']);

    Livewire::actingAs(lsdAdmin())->test(TimeOffSettings::class)
        ->assertOk()
        ->assertSee('not leave entitlement');
});

test('sandwich mode is described rather than flagged', function () {
    lsdPolicy(null);
    lsdType(['name' => 'Unauthorized Leave', 'code' => 'UNA', 'category' => 'unauthorized', 'sandwich_mode' => 'off']);

    Livewire::actingAs(lsdAdmin())->test(TimeOffSettings::class)
        ->assertOk()
        ->assertSee('Sandwich: Off')
        ->assertDontSee('Sandwich: Yes');
});

test('a bridged sandwich rule says what it bridges', function () {
    lsdPolicy(null);
    lsdType(['name' => 'Bridged', 'code' => 'BRG', 'is_sandwich_applicable' => true, 'sandwich_mode' => 'weekends_holidays']);

    Livewire::actingAs(lsdAdmin())->test(TimeOffSettings::class)
        ->assertOk()
        ->assertSee('Weekends + public holidays bridged');
});

test('retired types are not offered in settings', function () {
    lsdPolicy(null);
    $retired = lsdType(['name' => 'Casual Leave', 'code' => 'CL']);
    $retired->delete();

    Livewire::actingAs(lsdAdmin())->test(TimeOffSettings::class)
        ->assertOk()
        ->assertViewHas('leaveTypes', fn ($types) => ! $types->pluck('code')->contains('CL'))
        ->assertViewHas('retiredCount', fn ($count) => $count >= 1);
});

test('an employee cannot open leave settings', function () {
    lsdPolicy(null);

    Livewire::actingAs(User::factory()->create(['role' => UserRole::Employee]))
        ->test(TimeOffSettings::class)
        ->assertForbidden();
});
