<?php

use App\Enums\UserRole;
use App\Exceptions\ApprovalNotPermitted;
use App\Livewire\Employees\EmployeeEdit;
use App\Livewire\Performance\Goals;
use App\Livewire\Performance\ManagePips;
use App\Livewire\Performance\MyReview;
use App\Livewire\Performance\TeamReviews;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\PerformanceReview;
use App\Models\ReviewGoal;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Services\Performance\PipService;
use App\Services\Performance\ReviewWorkflowService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Phase 1 safety — an id arriving from the browser never reaches a record
 * the user does not own or manage.
 */
function ownedEmployee(UserRole $role = UserRole::Employee, array $attributes = []): Employee
{
    return Employee::factory()->create(array_merge([
        'user_id' => User::factory()->create(['role' => $role])->id,
        'status' => 'active',
        'manager_id' => null,
    ], $attributes));
}

function ownedReview(Employee $employee, string $status = 'draft'): PerformanceReview
{
    return PerformanceReview::create(['employee_id' => $employee->id, 'type' => 'self', 'status' => $status]);
}

test('an employee cannot open a colleague\'s performance review', function () {
    $me = ownedEmployee();
    $colleagueReview = ownedReview(ownedEmployee());

    expect(fn () => Livewire::actingAs($me->user)->test(MyReview::class)->call('openReview', $colleagueReview->id))
        ->toThrow(ModelNotFoundException::class);
});

test('a self review can only be submitted by the reviewee', function () {
    $review = ownedReview(ownedEmployee());
    $intruder = User::factory()->create();

    expect(fn () => app(ReviewWorkflowService::class)->submitSelfReview($review, [], $intruder))
        ->toThrow(ApprovalNotPermitted::class);
});

test('a manager cannot manager-review their own or an unrelated review', function () {
    $manager = ownedEmployee(UserRole::Manager);
    $ownReview = ownedReview($manager, 'submitted');
    $strangerReview = ownedReview(ownedEmployee(), 'submitted');

    Livewire::actingAs($manager->user)->test(TeamReviews::class)
        ->call('openManagerReview', $ownReview->id)
        ->assertForbidden();

    Livewire::actingAs($manager->user)->test(TeamReviews::class)
        ->call('openManagerReview', $strangerReview->id)
        ->assertForbidden();
});

test('goals can only be edited, toggled or deleted by their owner', function () {
    $me = ownedEmployee();
    $theirGoal = ReviewGoal::create(['employee_id' => ownedEmployee()->id, 'title' => 'Ship v2']);

    expect(fn () => Livewire::actingAs($me->user)->test(Goals::class)->call('delete', $theirGoal->id))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => Livewire::actingAs($me->user)->test(Goals::class)->call('toggleComplete', $theirGoal->id))
        ->toThrow(ModelNotFoundException::class);

    expect($theirGoal->fresh())->not->toBeNull()
        ->and($theirGoal->fresh()->is_completed)->toBeFalse();
});

test('a manager only sees and opens improvement plans inside their reporting line', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $report = ownedEmployee(attributes: ['manager_id' => $manager->id]);
    $stranger = ownedEmployee();

    $plan = fn (Employee $e) => app(PipService::class)->create($e, [
        'review_period_days' => 30, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
        'action_plan' => 'Improve turnaround', 'success_criteria' => 'Meet deadlines',
    ], $hr);
    $mine = $plan($report);
    $theirs = $plan($stranger);

    $component = Livewire::actingAs($manager)->test(ManagePips::class);
    $ids = $component->viewData('records')->pluck('id')->all();

    expect($ids)->toContain($mine->id)->not->toContain($theirs->id);

    expect(fn () => $component->call('viewRecord', $theirs->id))->toThrow(ModelNotFoundException::class);
});

test('the salary editor cannot load or change another employee\'s salary row', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = ownedEmployee();
    $other = ownedEmployee();
    $component = SalaryComponent::create(['name' => 'Basic', 'type' => 'earning', 'component_type' => 'earning', 'default_amount' => 1000]);
    $otherRow = EmployeeSalary::create(['employee_id' => $other->id, 'salary_component_id' => $component->id, 'amount' => 5000]);

    expect(fn () => Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => $employee])->call('openEditSalary', $otherRow->id))
        ->toThrow(ModelNotFoundException::class);

    expect((float) $otherRow->fresh()->amount)->toBe(5000.0);
});

test('the salary row being edited is locked against client tampering', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);

    Livewire::actingAs($hr)->test(EmployeeEdit::class, ['employee' => ownedEmployee()])
        ->set('editingSalaryId', 999);
})->throws(CannotUpdateLockedPropertyException::class);
