<?php

use App\Enums\UserRole;
use App\Livewire\Payroll\MyPayslips;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\User;
use Livewire\Livewire;

/**
 * Payslip for a given month offset back from today.
 *
 * Named distinctly from CombinedPayslipPrintTest's helper: Pest loads every test
 * file into the same process, so a duplicate function name is a fatal redeclare.
 */
function filterPayslipFor(Employee $employee, int $monthsAgo, float $gross = 50000): Payslip
{
    $period = now()->startOfMonth()->subMonths($monthsAgo);

    $payroll = Payroll::firstOrCreate(
        ['month' => $period->format('F'), 'year' => (int) $period->year],
        ['cycle' => 'cycle_a', 'status' => 'finalized'],
    );

    return Payslip::create([
        'payroll_id' => $payroll->id,
        'employee_id' => $employee->id,
        'gross_salary' => $gross,
        'total_deductions' => $gross * 0.1,
        'net_salary' => $gross * 0.9,
        'status' => 'paid',
    ]);
}

/** @return array{0: User, 1: Employee} */
function filterActor(): array
{
    $user = User::factory()->create(['role' => UserRole::Employee]);
    $employee = Employee::factory()->create(['user_id' => $user->id]);

    return [$user, $employee];
}

/** periodTotals is view data rather than a public property, so assertSet cannot reach it. */
function assertMonths(int $expected): Closure
{
    return fn (array $totals) => $totals['months'] === $expected;
}

test('last 3 months filter returns only the last three payslips', function () {
    [$user, $employee] = filterActor();

    foreach (range(0, 5) as $ago) {
        filterPayslipFor($employee, $ago);
    }

    Livewire::actingAs($user)->test(MyPayslips::class)
        ->set('filterPeriod', 'last_3')
        ->assertViewHas('periodTotals', assertMonths(3));
});

test('last 6 months filter returns six payslips', function () {
    [$user, $employee] = filterActor();

    foreach (range(0, 7) as $ago) {
        filterPayslipFor($employee, $ago);
    }

    Livewire::actingAs($user)->test(MyPayslips::class)
        ->set('filterPeriod', 'last_6')
        ->assertViewHas('periodTotals', assertMonths(6));
});

test('period totals sum the whole filtered window, not just the visible page', function () {
    [$user, $employee] = filterActor();

    // 6 payslips at 50,000 gross — the table paginates at 5, so totals must
    // cover all six rather than only the page being shown.
    foreach (range(0, 5) as $ago) {
        filterPayslipFor($employee, $ago, 50000);
    }

    Livewire::actingAs($user)->test(MyPayslips::class)
        ->set('filterPeriod', 'last_6')
        ->assertViewHas('periodTotals', function (array $totals) {
            expect($totals['months'])->toBe(6);
            expect(round($totals['gross'], 2))->toBe(300000.00);
            expect(round($totals['deductions'], 2))->toBe(30000.00);
            expect(round($totals['net'], 2))->toBe(270000.00);

            return true;
        });
});

test('the all filter applies no period restriction', function () {
    [$user, $employee] = filterActor();

    foreach (range(0, 9) as $ago) {
        filterPayslipFor($employee, $ago);
    }

    Livewire::actingAs($user)->test(MyPayslips::class)
        ->set('filterPeriod', 'all')
        ->assertViewHas('periodTotals', assertMonths(10));
});

test('a month filter narrows to that single month', function () {
    [$user, $employee] = filterActor();

    foreach (range(0, 3) as $ago) {
        filterPayslipFor($employee, $ago);
    }

    $target = now()->startOfMonth()->subMonths(2);

    Livewire::actingAs($user)->test(MyPayslips::class)
        ->set('filterPeriod', 'month')
        ->set('filterMonth', $target->format('F'))
        ->set('filterYear', (string) $target->year)
        ->assertViewHas('periodTotals', assertMonths(1));
});

test('an incomplete custom range does not filter anything out', function () {
    [$user, $employee] = filterActor();

    foreach (range(0, 2) as $ago) {
        filterPayslipFor($employee, $ago);
    }

    // Only "from" is set — the range is unusable, so fall back to unrestricted.
    Livewire::actingAs($user)->test(MyPayslips::class)
        ->set('filterPeriod', 'custom')
        ->set('rangeFrom', now()->format('Y-m'))
        ->assertViewHas('periodTotals', assertMonths(3));
});

test('a custom range covers its months inclusively', function () {
    [$user, $employee] = filterActor();

    foreach (range(0, 5) as $ago) {
        filterPayslipFor($employee, $ago);
    }

    Livewire::actingAs($user)->test(MyPayslips::class)
        ->set('filterPeriod', 'custom')
        ->set('rangeFrom', now()->startOfMonth()->subMonths(2)->format('Y-m'))
        ->set('rangeTo', now()->format('Y-m'))
        ->assertViewHas('periodTotals', assertMonths(3));
});

test('reset filters clears every filter and the selection', function () {
    [$user, $employee] = filterActor();
    $slip = filterPayslipFor($employee, 0);

    Livewire::actingAs($user)->test(MyPayslips::class)
        ->set('filterPeriod', 'last_3')
        ->set('selected', [$slip->id])
        ->call('resetFilters')
        ->assertSet('filterPeriod', 'all')
        ->assertSet('filterMonth', '')
        ->assertSet('rangeFrom', '')
        ->assertSet('selected', []);
});

test('my payslips renders for staff with no employee record', function () {
    // Regression: the empty path handed the view a Collection while the blade
    // calls paginator methods ($payslips->firstItem()), which 500'd for any
    // HR/admin account without an employee record.
    $user = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($user)->test(MyPayslips::class)
        ->assertOk()
        ->assertViewHas('periodTotals', assertMonths(0));
});
