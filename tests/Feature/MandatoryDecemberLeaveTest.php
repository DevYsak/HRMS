<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\MyTimeOff;
use App\Livewire\TimeOff\TimeOffSettings;
use App\Models\DecemberMandatoryDay;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveService;
use Database\Seeders\LeaveSeeder;
use Database\Seeders\LeaveTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * MDL means Mandatory December Leave — the six December shutdown days — and
 * nothing else.
 *
 * It was also the category on Maternity Leave, so the employee's "MDL" balance
 * card showed maternity while the policy text on the same page described the
 * December shutdown. One identifier, two meanings, on one screen.
 *
 * The two concepts are now separate and stay separate:
 *   MDL  → december_mandatory_days, fixed dates, not a balance
 *   ML   → Maternity Leave, category 'maternity', a real balance
 */
function mdlEmployee(): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create(['user_id' => $user->id, 'status' => 'active']);
}

test('1 — maternity leave carries the maternity category', function () {
    $this->seed(LeaveTypeSeeder::class);

    $maternity = LeaveType::where('name', 'like', '%Maternity%')->first();

    expect($maternity)->not->toBeNull()
        ->and($maternity->category)->toBe('maternity')
        // The established code is unchanged.
        ->and($maternity->code)->toBe('ML');
});

test('2 and 9 — a fresh seed leaves no leave type on the mdl category', function () {
    $this->seed(LeaveTypeSeeder::class);
    $this->seed(LeaveSeeder::class);

    expect(LeaveType::where('category', 'mdl')->count())->toBe(0)
        ->and(LeaveType::where('category', 'maternity')->count())->toBeGreaterThan(0);
});

test('the mdl category is no longer a legal value at all', function () {
    // Enforced by the column, not merely by convention.
    expect(fn () => LeaveType::create([
        'name' => 'Illegal', 'code' => 'ILL', 'category' => 'mdl',
    ]))->toThrow(QueryException::class);
});

test('3 and 4 — MDL is the December shutdown, held as dates rather than a balance', function () {
    $day = DecemberMandatoryDay::create([
        'year' => 2026, 'date' => '2026-12-28', 'description' => 'December Mandatory Leave - Day 3',
    ]);

    expect(DecemberMandatoryDay::isMandatory(Carbon::parse('2026-12-28')))->toBeTrue()
        ->and(DecemberMandatoryDay::isMandatory(Carbon::parse('2026-12-01')))->toBeFalse()
        ->and($day->description)->toContain('December Mandatory Leave')
        // It is deliberately not a leave type — no balance, no application.
        ->and(LeaveType::where('name', 'like', '%Mandatory December%')->count())->toBe(0);
});

test('5 — working a mandatory December day still earns a comp-off credit', function () {
    // The existing rule, unchanged by the rename.
    $this->seed(LeaveTypeSeeder::class);
    $employee = mdlEmployee();
    $date = '2026-12-28';

    DecemberMandatoryDay::create(['year' => 2026, 'date' => $date, 'description' => 'December Mandatory Leave - Day 3']);

    $compOffType = LeaveType::where('category', 'comp_off')->first();
    expect($compOffType)->not->toBeNull();

    $before = (float) (LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $compOffType->id)->value('comp_off_credits') ?? 0);

    app(LeaveService::class)->creditCompOff($employee, Carbon::parse($date));

    $after = (float) LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $compOffType->id)->value('comp_off_credits');

    expect($after)->toBe($before + 1.0);
});

test('6 — a maternity balance is never presented as MDL', function () {
    $this->seed(LeaveTypeSeeder::class);
    // EmployeeObserver seeds the standard balances on creation, maternity
    // among them — no need to add one, and adding one collides.
    $employee = mdlEmployee();
    $maternity = LeaveType::where('category', 'maternity')->first();

    expect(LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $maternity->id)->exists())->toBeTrue();

    Livewire::actingAs($employee->user)->test(MyTimeOff::class)
        ->assertOk()
        ->assertViewHas('highlightBalances', function ($h) use ($maternity) {
            // Resolved under its own name, and there is no 'mdl' slot to
            // mistake it for.
            return ! array_key_exists('mdl', $h)
                && ($h['maternity']?->leave_type_id ?? null) === $maternity->id;
        });
});

test('7 — the maternity slot never falls back to an arbitrary third leave type', function () {
    // The bug: with no maternity balance the card reached for
    // $firstBalances->get(2) and showed whatever happened to be third,
    // labelled as somebody else's leave.
    $this->seed(LeaveTypeSeeder::class);
    $employee = mdlEmployee();

    // Remove only the maternity balance, leaving several others in place —
    // the exact shape that used to make the card show the third one.
    $maternityId = LeaveType::where('category', 'maternity')->value('id');
    LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $maternityId)->delete();

    expect(LeaveBalance::where('employee_id', $employee->id)->count())->toBeGreaterThanOrEqual(3);

    Livewire::actingAs($employee->user)->test(MyTimeOff::class)
        ->assertOk()
        ->assertViewHas('highlightBalances', fn ($h) => $h['maternity'] === null);
});

test('8 — an employee with no maternity balance simply has none', function () {
    $this->seed(LeaveTypeSeeder::class);
    $employee = mdlEmployee();

    $maternityId = LeaveType::where('category', 'maternity')->value('id');
    LeaveBalance::where('employee_id', $employee->id)->where('leave_type_id', $maternityId)->delete();

    Livewire::actingAs($employee->user)->test(MyTimeOff::class)
        ->assertOk()
        ->assertViewHas('highlightBalances', fn ($h) => $h['maternity'] === null);
});

test('the December shutdown dates still reach the employee page', function () {
    // MDL is surfaced as dates, which is what it is.
    $employee = mdlEmployee();
    DecemberMandatoryDay::create([
        'year' => now()->year, 'date' => now()->year.'-12-29',
        'description' => 'December Mandatory Leave - Day 4',
    ]);

    Livewire::actingAs($employee->user)->test(MyTimeOff::class)
        ->assertOk()
        ->assertViewHas('mandatoryDays', fn ($d) => $d->count() === 1)
        ->assertSee('December Mandatory Leave - Day 4');
});

test('10 — no code path resolves maternity through an mdl alias', function () {
    // Guards the regression directly: the matcher used to accept 'mdl' and
    // 'medical' as aliases for maternity.
    $source = file_get_contents(app_path('Livewire/TimeOff/MyTimeOff.php'));

    // Strip comments so the explanatory notes do not trip this.
    $code = preg_replace('~//.*$~m', '', $source);

    expect($code)->not->toContain("'mdl'")
        ->and($code)->not->toContain('"mdl"');
});

test('the settings screen offers maternity, never a combined Maternity / MDL option', function () {
    $this->seed(LeaveTypeSeeder::class);
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $type = LeaveType::where('category', 'maternity')->first();

    // The category picker lives inside the edit modal, so it has to be open
    // for the options to render at all.
    Livewire::actingAs($hr)->test(TimeOffSettings::class)
        ->assertOk()
        ->call('openModal', $type->id)
        ->assertSee('Maternity Leave')
        ->assertDontSee('Maternity / MDL')
        ->assertDontSee('>MDL<', false);
});
