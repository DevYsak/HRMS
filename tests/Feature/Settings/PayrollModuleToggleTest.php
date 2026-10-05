<?php

use App\Enums\UserRole;
use App\Livewire\Payroll\FinanceApproval;
use App\Livewire\Payroll\MyPayslips;
use App\Livewire\Payroll\Process;
use App\Livewire\Settings\ModuleSettings;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ModuleSetting;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\User;
use App\Services\EmployeeDashboardService;
use App\Services\ModuleFeatureService;
use App\Services\PayrollService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Payroll & Payslips is a company module switch (System Settings › Modules).
 * Off: every payroll and payslip page, link, widget and action is hidden AND
 * refused on the server; no record is deleted. On: everything works exactly
 * as before, still under each user's permissions.
 */
function pmUser(UserRole $role): User
{
    $user = User::factory()->create(['role' => $role]);
    Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'manager_id' => null, 'joining_date' => '2024-01-08', 'salary_cycle' => 'cycle_a']);

    return $user->fresh();
}

/** A finalized run with one payslip for $user. */
function pmPayslip(User $user): Payslip
{
    $payroll = Payroll::create(['month' => 'August', 'year' => 2026, 'cycle' => 'cycle_a', 'status' => 'finalized', 'processed_by' => $user->id]);

    return Payslip::create(['payroll_id' => $payroll->id, 'employee_id' => $user->employee->id, 'gross_salary' => 30000,
        'total_deductions' => 0, 'net_salary' => 30000, 'status' => 'paid']);
}

function pmDisable(): void
{
    app(ModuleFeatureService::class)->setPayroll(false, User::factory()->create(['role' => UserRole::SuperAdmin]));
}

beforeEach(fn () => Notification::fake());

// ── Enabled: nothing changes ───────────────────────────────────────────────

test('enabled: Finance, HR and employees keep their payroll and payslip access', function () {
    $finance = pmUser(UserRole::Finance);
    $hr = pmUser(UserRole::HrAdmin);
    $employee = pmUser(UserRole::Employee);

    $this->actingAs($finance)->get(route('payroll.finance-approve'))->assertOk();
    $this->actingAs($hr)->get(route('payroll.process'))->assertOk()->assertSee('Run Payroll');
    $this->actingAs($employee)->get(route('payroll.payslips'))->assertOk();

    expect(app(EmployeeDashboardService::class)->build($employee)['quickActions'] ?? null)->not->toBeNull();
});

test('enabled: an employee still cannot open payroll processing — the module grants no permission', function () {
    $this->actingAs(pmUser(UserRole::Employee))->get(route('payroll.process'))->assertForbidden();
});

// ── Disabled: hidden ───────────────────────────────────────────────────────

test('disabled: payroll and payslip links disappear from the sidebar', function () {
    $hr = pmUser(UserRole::HrAdmin);
    $this->actingAs($hr)->get(route('attendance.my'))->assertOk()->assertSee('Run Payroll')->assertSee('My Payslip');

    pmDisable();

    $this->actingAs($hr)->get(route('attendance.my'))->assertOk()
        ->assertDontSee('Run Payroll')
        ->assertDontSee('My Payslip')
        ->assertDontSee('Payroll Register');
});

test('disabled: the employee dashboard drops the payslip card, KPI and quick action without a gap', function () {
    $employee = pmUser(UserRole::Employee);
    pmPayslip($employee);
    pmDisable();

    $data = app(EmployeeDashboardService::class)->build($employee);

    expect($data['payroll'])->toBeNull()
        ->and(collect($data['kpis'])->pluck('label')->all())->not->toContain('Salary')
        ->and(collect($data['quickActions'])->pluck('route')->all())->not->toContain('payroll.payslips');
});

test('disabled: Finance lands on self-service and the approval queue is gone', function () {
    $finance = pmUser(UserRole::Finance);
    pmDisable();

    $this->actingAs($finance)->get(route('dashboard'))->assertOk()->assertDontSee('Finance Approval');
    $this->actingAs($finance)->get(route('dashboard.finance'))->assertForbidden();
});

test('disabled: the HR dashboard shows no payroll card or Run Payroll action', function () {
    $hr = pmUser(UserRole::HrAdmin);
    pmDisable();

    $this->actingAs($hr)->get(route('dashboard'))->assertOk()
        ->assertDontSee('Payroll run')
        ->assertDontSee('Run Payroll');
});

// ── Disabled: refused on the server ────────────────────────────────────────

test('disabled: every payroll and payslip URL is refused', function () {
    $hr = pmUser(UserRole::HrAdmin);
    $employee = pmUser(UserRole::Employee);
    $slip = pmPayslip($employee);
    pmDisable();

    foreach (['payroll.overview', 'payroll.process', 'payroll.components', 'payroll.structures', 'payroll.incentives',
        'payroll.reimbursements', 'payroll.finance-approve', 'reports.payroll-register', 'settings.salary-cycles'] as $route) {
        $this->actingAs($hr)->get(route($route))->assertForbidden();
    }

    $this->actingAs($employee)->get(route('payroll.payslips'))->assertForbidden();
    $this->actingAs($employee)->get(route('payroll.payslips.download', $slip))->assertForbidden();
});

test('disabled: Livewire payroll and payslip actions are refused, even on a page opened before', function () {
    $hr = pmUser(UserRole::HrAdmin);
    $finance = pmUser(UserRole::Finance);
    $employee = pmUser(UserRole::Employee);
    pmDisable();

    Livewire::actingAs($hr)->test(Process::class)->assertForbidden();
    Livewire::actingAs($finance)->test(FinanceApproval::class)->assertForbidden();
    Livewire::actingAs($employee)->test(MyPayslips::class)->assertForbidden();
});

test('disabled: payroll generation and Finance approval are refused by the service, so nothing is notified', function () {
    $hr = pmUser(UserRole::HrAdmin);
    $finance = pmUser(UserRole::Finance);
    $pending = Payroll::create(['month' => 'September', 'year' => 2026, 'cycle' => 'cycle_a', 'status' => 'pending_finance', 'processed_by' => $hr->id]);
    pmDisable();
    $this->actingAs($finance);

    expect(fn () => app(PayrollService::class)->generateDraft('October', 2026, 'cycle_a', $hr->id))->toThrow(HttpException::class)
        ->and(fn () => app(PayrollService::class)->approveFinance($pending->fresh(), $finance->id))->toThrow(HttpException::class)
        ->and(Payroll::where('month', 'October')->exists())->toBeFalse()
        ->and($pending->fresh()->status)->toBe('pending_finance');
    Notification::assertNothingSent();
});

// ── Data and re-enable ─────────────────────────────────────────────────────

test('disabling never touches a record, and re-enabling restores access', function () {
    $employee = pmUser(UserRole::Employee);
    $slip = pmPayslip($employee);
    $before = [Payroll::count(), Payslip::count(), $employee->employee->fresh()->salary_cycle];

    pmDisable();
    expect([Payroll::count(), Payslip::count(), $employee->employee->fresh()->salary_cycle])->toBe($before);

    app(ModuleFeatureService::class)->setPayroll(true, User::factory()->create(['role' => UserRole::SuperAdmin]));

    $this->actingAs($employee)->get(route('payroll.payslips'))->assertOk();
    expect($slip->fresh())->not->toBeNull();
});

// ── The switch itself ──────────────────────────────────────────────────────

test('only a settings admin can switch the module, disabling needs confirmation, and the change is audited', function () {
    $admin = pmUser(UserRole::SuperAdmin);

    Livewire::actingAs(pmUser(UserRole::Employee))->test(ModuleSettings::class)->assertForbidden();
    Livewire::actingAs(pmUser(UserRole::Manager))->test(ModuleSettings::class)->assertForbidden();

    $page = Livewire::actingAs($admin)->test(ModuleSettings::class)
        ->call('askDisablePayroll')
        ->assertSet('confirmingDisable', true)
        ->assertSee('Disable Payroll & Payslips?');
    expect(ModuleSetting::current()->payroll_enabled)->toBeTrue(); // nothing yet

    $page->call('confirmDisablePayroll');

    $audit = AuditLog::where('auditable_type', ModuleSetting::class)->latest('id')->first();
    expect(ModuleSetting::current()->payroll_enabled)->toBeFalse()
        ->and(ModuleSetting::current()->payslips_enabled)->toBeFalse()
        ->and($audit)->not->toBeNull()
        ->and($audit->old_values)->toMatchArray(['payroll_enabled' => true])
        ->and($audit->new_values)->toMatchArray(['payroll_enabled' => false, 'changed_by' => $admin->email]);

    // Payslips cannot come back on their own while payroll is off.
    $page->call('togglePayslips');
    expect(app(ModuleFeatureService::class)->payslipsEnabled())->toBeFalse();
});

test('disabled: an automated payroll run exits safely and generates nothing', function () {
    pmUser(UserRole::SuperAdmin);
    $employee = pmUser(UserRole::Employee);
    pmDisable();

    $this->artisan('uat:prepare-employee', ['email' => $employee->email])
        ->expectsOutputToContain('Payroll module disabled — skipped.');

    expect(Payroll::count())->toBe(0)->and(Payslip::count())->toBe(0);
});
