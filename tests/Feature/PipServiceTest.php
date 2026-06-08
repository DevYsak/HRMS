<?php

use App\Models\Employee;
use App\Models\PipRecord;
use App\Models\User;
use App\Services\Performance\PipService;

// ─── Helpers ──────────────────────────────────────────────────────────────────

function pipEmployee(?array $attrs = []): Employee
{
    $user = User::factory()->create();

    return Employee::factory()->create(array_merge(['user_id' => $user->id], $attrs));
}

function startPip(Employee $employee, User $manager, array $overrides = []): PipRecord
{
    return app(PipService::class)->create($employee, array_merge([
        'review_period_days' => 90,
        'start_date' => '2026-09-01',
        'end_date' => '2026-11-30',
        'action_plan' => 'Improve code review turnaround time and communication.',
        'success_criteria' => 'Consistently meet sprint deadlines for two consecutive cycles.',
    ], $overrides), $manager);
}

// ─── Creation & activation ────────────────────────────────────────────────────

it('creates a draft pip record awaiting hr activation', function () {
    $employee = pipEmployee();
    $manager = User::factory()->create();

    $pip = startPip($employee, $manager);

    expect($pip->status)->toBe('draft')
        ->and($pip->employee_id)->toBe($employee->id)
        ->and($pip->manager_id)->toBe($manager->id)
        ->and($pip->current_reviewer_stage)->toBe('hr');
});

it('lets hr activate a draft pip', function () {
    $employee = pipEmployee();
    $manager = User::factory()->create();
    $hr = User::factory()->create();

    $pip = startPip($employee, $manager);

    app(PipService::class)->activate($pip, $hr);

    expect($pip->fresh())
        ->status->toBe('active')
        ->hr_reviewer_id->toBe($hr->id)
        ->current_reviewer_stage->toBe('manager');
});

it('blocks activating a pip that is not in draft status', function () {
    $employee = pipEmployee();
    $manager = User::factory()->create();
    $hr = User::factory()->create();

    $pip = startPip($employee, $manager);
    app(PipService::class)->activate($pip, $hr);

    expect(fn () => app(PipService::class)->activate($pip->fresh(), $hr))
        ->toThrow(DomainException::class, 'Only draft PIPs can be activated.');
});

// ─── Goals & progress ─────────────────────────────────────────────────────────

it('adds milestone goals to a pip', function () {
    $employee = pipEmployee();
    $manager = User::factory()->create();

    $pip = startPip($employee, $manager);

    $goal = app(PipService::class)->addGoal($pip, [
        'title' => 'Reduce review turnaround to under 1 day',
        'description' => 'Respond to PR review requests within one business day.',
        'target_date' => '2026-10-15',
        'weightage' => 50,
    ]);

    expect($goal->pip_record_id)->toBe($pip->id)
        ->and($goal->title)->toBe('Reduce review turnaround to under 1 day')
        ->and($goal->status)->toBe('not_started');
});

it('tracks overall progress as the average of goal progress', function () {
    $employee = pipEmployee();
    $manager = User::factory()->create();

    $pip = startPip($employee, $manager);
    $goalA = app(PipService::class)->addGoal($pip, ['title' => 'Goal A', 'weightage' => 50]);
    $goalB = app(PipService::class)->addGoal($pip, ['title' => 'Goal B', 'weightage' => 50]);

    app(PipService::class)->updateGoalProgress($goalA, 100, 'Fully achieved.', $manager);
    app(PipService::class)->updateGoalProgress($goalB, 50, 'Halfway there.', $manager);

    expect($goalA->fresh()->status)->toBe('achieved')
        ->and($goalB->fresh()->status)->toBe('in_progress')
        ->and($pip->fresh()->overallProgress())->toBe(75.0);
});

it('blocks adding goals once a final outcome has been recorded', function () {
    $employee = pipEmployee();
    $manager = User::factory()->create();
    $hr = User::factory()->create();

    $pip = startPip($employee, $manager);
    app(PipService::class)->activate($pip, $hr);
    app(PipService::class)->recordOutcome($pip->fresh(), 'successful', $hr);

    expect(fn () => app(PipService::class)->addGoal($pip->fresh(), ['title' => 'Late goal', 'weightage' => 25]))
        ->toThrow(DomainException::class, 'Goals can only be added to draft or active PIPs.');
});

// ─── Stage advancement ────────────────────────────────────────────────────────

it('advances a pip to the next reviewer stage and marks it under review', function () {
    $employee = pipEmployee();
    $manager = User::factory()->create();
    $hr = User::factory()->create();

    $pip = startPip($employee, $manager);
    app(PipService::class)->activate($pip, $hr);

    app(PipService::class)->advance($pip->fresh(), 'dept_head', $hr);

    expect($pip->fresh())
        ->current_reviewer_stage->toBe('dept_head')
        ->status->toBe('under_review');
});

it('rejects advancing a pip to an invalid reviewer stage', function () {
    $employee = pipEmployee();
    $manager = User::factory()->create();
    $hr = User::factory()->create();

    $pip = startPip($employee, $manager);
    app(PipService::class)->activate($pip, $hr);

    expect(fn () => app(PipService::class)->advance($pip->fresh(), 'ceo', $hr))
        ->toThrow(DomainException::class, 'Invalid reviewer stage: ceo.');
});

// ─── Outcomes ─────────────────────────────────────────────────────────────────

it('records a final outcome on an active pip', function () {
    $employee = pipEmployee();
    $manager = User::factory()->create();
    $hr = User::factory()->create();

    $pip = startPip($employee, $manager);
    app(PipService::class)->activate($pip, $hr);

    app(PipService::class)->recordOutcome($pip->fresh(), 'successful', $hr);

    expect($pip->fresh())
        ->status->toBe('successful')
        ->outcome->toBe('successful')
        ->outcome_date->not->toBeNull();
});

it('rejects an invalid outcome value', function () {
    $employee = pipEmployee();
    $manager = User::factory()->create();
    $hr = User::factory()->create();

    $pip = startPip($employee, $manager);
    app(PipService::class)->activate($pip, $hr);

    expect(fn () => app(PipService::class)->recordOutcome($pip->fresh(), 'bogus', $hr))
        ->toThrow(DomainException::class, 'Invalid outcome');
});

it('blocks recording an outcome on a draft pip', function () {
    $employee = pipEmployee();
    $manager = User::factory()->create();

    $pip = startPip($employee, $manager);

    expect(fn () => app(PipService::class)->recordOutcome($pip, 'successful', $manager))
        ->toThrow(DomainException::class, 'Only active PIPs under review can have an outcome recorded.');
});

// ─── Employee comments ────────────────────────────────────────────────────────

it('lets the employee submit comments on their own pip', function () {
    $employee = pipEmployee();
    $manager = User::factory()->create();

    $pip = startPip($employee, $manager);

    app(PipService::class)->submitEmployeeComment($pip, $employee->user, 'I am committed to improving and appreciate the support.');

    expect($pip->fresh()->employee_comments)->toBe('I am committed to improving and appreciate the support.');
});

it('blocks submitting comments on someone else\'s pip', function () {
    $employee = pipEmployee();
    $otherEmployee = pipEmployee();
    $manager = User::factory()->create();

    $pip = startPip($employee, $manager);

    expect(fn () => app(PipService::class)->submitEmployeeComment($pip, $otherEmployee->user, 'Not mine to comment on.'))
        ->toThrow(DomainException::class, 'You may only comment on your own PIP.');
});
