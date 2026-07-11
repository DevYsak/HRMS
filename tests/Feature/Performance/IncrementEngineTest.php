<?php

use App\Enums\UserRole;
use App\Livewire\Performance\IncrementCenter;
use App\Mail\IncrementLetterMail;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\EmployeeScorecard;
use App\Models\IncrementCycle;
use App\Models\PerformanceCycle;
use App\Models\PerformanceTemplate;
use App\Models\PipRecord;
use App\Models\SalaryComponent;
use App\Models\SalaryRevision;
use App\Models\User;
use App\Notifications\IncrementAppliedNotification;
use App\Notifications\PipCreatedNotification;
use App\Services\Increments\CalibrationService;
use App\Services\Increments\IncrementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * v4 Phase E — increment engine: calibration math (z + small-dept fallback),
 * annual-score windows, budget enforcement, and effective-dated salary apply.
 */
function fyCycle(User $actor, float $budgetPercent = 10): IncrementCycle
{
    return app(IncrementService::class)->openCycle('2026-27', '2026-07-01', $budgetPercent, $actor);
}

/** A quarterly scorecard whose performance cycle ends inside the FY window. */
function scorecardFor(Employee $employee, float $score, string $cycleEnd, User $actor): void
{
    $template = PerformanceTemplate::firstOrCreate(
        ['code' => 'INC-T'],
        ['name' => 'Inc Template', 'applies_to_type' => 'global', 'cycle_type' => 'quarterly', 'is_active' => true, 'created_by' => $actor->id],
    );

    $cycle = PerformanceCycle::create([
        'name' => 'Q ending '.$cycleEnd, 'template_id' => $template->id, 'cycle_type' => 'quarterly',
        'start_date' => Carbon::parse($cycleEnd)->subMonths(3)->toDateString(),
        'end_date' => $cycleEnd, 'status' => 'completed', 'created_by' => $actor->id,
    ]);

    EmployeeScorecard::create([
        'employee_id' => $employee->id, 'performance_cycle_id' => $cycle->id, 'template_id' => $template->id,
        'total_weighted_score' => $score, 'final_score' => $score,
        'grade' => EmployeeScorecard::computeGrade($score), 'generated_at' => now(),
    ]);
}

/** Give the employee a single fixed earning of ₹gross/month. */
function salaryFor(Employee $employee, float $gross): void
{
    $component = SalaryComponent::firstOrCreate(
        ['code' => 'BASIC-INC'],
        ['name' => 'Basic', 'type' => 'earning', 'component_type' => 'earning', 'calculation_type' => 'fixed', 'is_active' => true],
    );

    EmployeeSalary::create([
        'employee_id' => $employee->id,
        'salary_component_id' => $component->id,
        'amount' => $gross,
        'effective_from' => '2025-07-01',
        'effective_to' => null,
    ]);
}

test('z-score calibration bands a big department; identical scores all land in C', function () {
    $calibration = app(CalibrationService::class);

    // scores [10,10,10,10,90]: mean 26, sd 32 → z(90)=2 → A, z(10)=-0.5 → D
    $scored = collect([10, 10, 10, 10, 90])->map(fn ($s) => ['employee' => new Employee, 'score' => (float) $s]);
    $result = $calibration->calibrateDepartment($scored);

    expect($result->last()['band'])->toBe('A')
        ->and($result->last()['z'])->toBe(2.0)
        ->and($result->first()['band'])->toBe('D');

    $flat = collect(array_fill(0, 6, ['employee' => new Employee, 'score' => 70.0]));
    expect($calibration->calibrateDepartment($flat)->pluck('band')->unique()->all())->toBe(['C']);
});

test('departments under 5 people skip z-scoring and map raw scores to bands', function () {
    $result = app(CalibrationService::class)->calibrateDepartment(collect([
        ['employee' => new Employee, 'score' => 92.0],
        ['employee' => new Employee, 'score' => 70.0],
        ['employee' => new Employee, 'score' => 30.0],
    ]));

    expect($result->pluck('band')->all())->toBe(['A', 'C', 'E'])
        ->and($result->pluck('z')->unique()->all())->toBe([null]);
});

test('the annual score averages quarters in the FY window and needs at least 2', function () {
    $actor = User::factory()->create();
    $cycle = fyCycle($actor);
    $employee = Employee::factory()->create(['status' => 'active']);

    // One quarter only → insufficient
    scorecardFor($employee, 80, '2025-09-30', $actor);
    expect(app(CalibrationService::class)->annualScore($employee, $cycle))
        ->toBe(['score' => null, 'quarters' => 1]);

    // Second quarter inside the window → average; a cycle after the window is ignored
    scorecardFor($employee, 60, '2025-12-31', $actor);
    scorecardFor($employee, 10, '2026-09-30', $actor); // outside window (>= effective date)

    expect(app(CalibrationService::class)->annualScore($employee, $cycle))
        ->toBe(['score' => 70.0, 'quarters' => 2]);
});

test('generated proposals carry band, matrix default percent and current gross', function () {
    Notification::fake();

    $actor = User::factory()->create(['role' => UserRole::HrAdmin]);
    $dept = Department::factory()->create();
    $cycle = fyCycle($actor);

    $employee = Employee::factory()->create(['status' => 'active', 'department_id' => $dept->id]);
    salaryFor($employee, 50000);
    scorecardFor($employee, 85, '2025-09-30', $actor);
    scorecardFor($employee, 95, '2025-12-31', $actor);

    app(IncrementService::class)->generateProposals($cycle, $actor);

    $proposal = $cycle->proposals()->where('employee_id', $employee->id)->firstOrFail();

    // Small dept → raw band on 90 avg = A → default 15%
    expect($proposal->annual_raw_score)->toBe(90.0)
        ->and($proposal->band)->toBe('A')
        ->and($proposal->calibrated_z)->toBeNull()
        ->and($proposal->current_gross)->toBe(50000.0)
        ->and($proposal->proposed_percent)->toBe(15.0)
        ->and($proposal->new_gross)->toBe(57500.0)
        ->and($cycle->fresh()->status)->toBe('calibration');
});

test('proposals outside the band range are rejected and approval enforces the budget', function () {
    Notification::fake();

    $actor = User::factory()->create(['role' => UserRole::HrAdmin]);
    // 1% budget — a 15% band-A increment must blow through it.
    $cycle = fyCycle($actor, budgetPercent: 1);

    $employee = Employee::factory()->create(['status' => 'active']);
    salaryFor($employee, 100000);
    scorecardFor($employee, 95, '2025-09-30', $actor);
    scorecardFor($employee, 95, '2025-12-31', $actor);

    $service = app(IncrementService::class);
    $service->generateProposals($cycle, $actor);
    $proposal = $cycle->proposals()->firstOrFail();

    // Band A allows 12–18% — 25% must throw.
    expect(fn () => $service->updateProposal($proposal, 25.0, null, $actor))
        ->toThrow(DomainException::class, 'Band A allows');

    $service->submitForApproval($cycle->fresh(), $actor);

    expect(fn () => $service->approveCycle($cycle->fresh(), $actor))
        ->toThrow(DomainException::class, 'Over budget');
});

test('applying a cycle writes effective-dated salary rows, a revision trail and the letter', function () {
    Notification::fake();
    Mail::fake();
    Storage::fake('local');

    $actor = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $cycle = fyCycle($actor, budgetPercent: 20);

    $employee = Employee::factory()->create(['status' => 'active']);
    salaryFor($employee, 50000);
    scorecardFor($employee, 95, '2025-09-30', $actor);
    scorecardFor($employee, 95, '2025-12-31', $actor);

    $service = app(IncrementService::class);
    $service->generateProposals($cycle, $actor);
    $service->submitForApproval($cycle->fresh(), $actor);
    $service->approveCycle($cycle->fresh(), $actor);
    $applied = $service->applyCycle($cycle->fresh(), $actor);

    $proposal = $cycle->proposals()->firstOrFail();

    // Old row closed the day before, new row opens on the effective date at +15%.
    $old = EmployeeSalary::where('employee_id', $employee->id)->whereNotNull('effective_to')->firstOrFail();
    $new = EmployeeSalary::where('employee_id', $employee->id)->whereNull('effective_to')->firstOrFail();

    expect($applied)->toBe(1)
        ->and($old->effective_to->toDateString())->toBe('2026-06-30')
        ->and($new->effective_from->toDateString())->toBe('2026-07-01')
        ->and((float) $new->amount)->toBe(57500.0)
        ->and(SalaryRevision::where('employee_id', $employee->id)->exists())->toBeTrue()
        ->and($proposal->fresh()->letter_path)->not->toBeNull()
        ->and($cycle->fresh()->status)->toBe('applied');

    Mail::assertQueued(IncrementLetterMail::class);
    Notification::assertSentTo($employee->user, IncrementAppliedNotification::class);
});

test('HR can open a cycle from the Increment Center', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire\Livewire::actingAs($hr)->test(IncrementCenter::class)
        ->call('newCycleForm')
        ->set('financialYear', '2027-28')
        ->set('effectiveDate', '2027-07-01')
        ->set('budgetPercent', '12')
        ->call('openCycle')
        ->assertHasNoErrors()
        ->assertSet('showCycleForm', false);

    $cycle = IncrementCycle::where('financial_year', '2027-28')->firstOrFail();
    expect($cycle->matrix()->count())->toBe(5)
        ->and($cycle->budget_percent)->toBe(12.0);
});

test('a plain employee cannot open the Increment Center', function () {
    $employee = Employee::factory()->create();

    Livewire\Livewire::actingAs($employee->user)->test(IncrementCenter::class)
        ->assertForbidden();
});

test('band E auto-creates a PIP and notifies HR', function () {
    Notification::fake();
    Mail::fake();
    Storage::fake('local');

    $actor = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $cycle = fyCycle($actor, budgetPercent: 20);

    $employee = Employee::factory()->create(['status' => 'active']);
    salaryFor($employee, 40000);
    scorecardFor($employee, 20, '2025-09-30', $actor); // raw band E (<40)
    scorecardFor($employee, 20, '2025-12-31', $actor);

    $service = app(IncrementService::class);
    $service->generateProposals($cycle, $actor);
    $service->submitForApproval($cycle->fresh(), $actor);
    $service->approveCycle($cycle->fresh(), $actor);
    $service->applyCycle($cycle->fresh(), $actor);

    expect(PipRecord::where('employee_id', $employee->id)->exists())->toBeTrue()
        ->and($cycle->proposals()->first()->proposed_percent)->toBe(0.0);

    Notification::assertSentTo($hr, PipCreatedNotification::class);
});
