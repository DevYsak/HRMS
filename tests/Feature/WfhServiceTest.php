<?php

use App\Models\Employee;
use App\Models\User;
use App\Models\WfhRequest;
use App\Services\WfhService;

// ─── Helpers ──────────────────────────────────────────────────────────────────

function wfhEmployee(?array $attrs = []): Employee
{
    $user = User::factory()->create();

    return Employee::factory()->create(array_merge(['user_id' => $user->id], $attrs));
}

// ─── Submission ───────────────────────────────────────────────────────────────

it('submits a pending wfh request', function () {
    $employee = wfhEmployee();

    $request = app(WfhService::class)->submitRequest($employee, [
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-02',
        'reason' => 'Internet installation at home office',
    ]);

    expect($request->status)->toBe('pending')
        ->and($request->employee_id)->toBe($employee->id)
        ->and($request->daysCount())->toBe(2.0);
});

it('blocks requests where the end date is before the start date', function () {
    $employee = wfhEmployee();

    expect(fn () => app(WfhService::class)->submitRequest($employee, [
        'start_date' => '2026-09-05',
        'end_date' => '2026-09-04',
        'reason' => 'Invalid range',
    ]))->toThrow(DomainException::class, 'end date cannot be before the start date');
});

it('blocks overlapping pending or approved requests', function () {
    $employee = wfhEmployee();
    $service = app(WfhService::class);

    $service->submitRequest($employee, [
        'start_date' => '2026-09-10',
        'end_date' => '2026-09-12',
        'reason' => 'First request',
    ]);

    expect(fn () => $service->submitRequest($employee, [
        'start_date' => '2026-09-11',
        'end_date' => '2026-09-13',
        'reason' => 'Overlapping request',
    ]))->toThrow(DomainException::class, 'overlap');
});

it('treats a half-day request as half a day', function () {
    $employee = wfhEmployee();

    $request = app(WfhService::class)->submitRequest($employee, [
        'start_date' => '2026-09-15',
        'end_date' => '2026-09-15',
        'reason' => 'Doctor appointment in the morning',
        'is_half_day' => true,
        'half_day_period' => 'second_half',
    ]);

    expect($request->daysCount())->toBe(0.5)
        ->and($request->half_day_period)->toBe('second_half');
});

// ─── Approval / rejection / cancellation ──────────────────────────────────────

it('approves a pending request and stamps reviewer metadata', function () {
    $employee = wfhEmployee();
    $reviewer = User::factory()->create();
    $service = app(WfhService::class);

    $request = $service->submitRequest($employee, [
        'start_date' => '2026-09-20',
        'end_date' => '2026-09-20',
        'reason' => 'Working remotely',
    ]);

    $service->approve($request, $reviewer->id, 'Approved, enjoy.');

    expect($request->fresh())
        ->status->toBe('approved')
        ->reviewer_id->toBe($reviewer->id)
        ->reviewer_comment->toBe('Approved, enjoy.')
        ->reviewed_at->not->toBeNull();
});

it('rejects a pending request with a comment', function () {
    $employee = wfhEmployee();
    $reviewer = User::factory()->create();
    $service = app(WfhService::class);

    $request = $service->submitRequest($employee, [
        'start_date' => '2026-09-21',
        'end_date' => '2026-09-21',
        'reason' => 'Working remotely',
    ]);

    $service->reject($request, $reviewer->id, 'Critical deadline requires office presence.');

    expect($request->fresh())
        ->status->toBe('rejected')
        ->reviewer_comment->toBe('Critical deadline requires office presence.');
});

it('blocks approving a request that is not pending', function () {
    $employee = wfhEmployee();
    $reviewer = User::factory()->create();
    $service = app(WfhService::class);

    $request = $service->submitRequest($employee, [
        'start_date' => '2026-09-22',
        'end_date' => '2026-09-22',
        'reason' => 'Working remotely',
    ]);
    $service->approve($request, $reviewer->id);

    expect(fn () => $service->approve($request->fresh(), $reviewer->id))
        ->toThrow(DomainException::class, 'Only pending');
});

it('blocks rejecting a request that is not pending', function () {
    $employee = wfhEmployee();
    $reviewer = User::factory()->create();
    $service = app(WfhService::class);

    $request = $service->submitRequest($employee, [
        'start_date' => '2026-09-23',
        'end_date' => '2026-09-23',
        'reason' => 'Working remotely',
    ]);
    $service->reject($request, $reviewer->id, 'No longer needed.');

    expect(fn () => $service->reject($request->fresh(), $reviewer->id, 'Trying again.'))
        ->toThrow(DomainException::class, 'Only pending');
});

it('allows a new overlapping request once the prior one is no longer pending or approved', function () {
    $employee = wfhEmployee();
    $reviewer = User::factory()->create();
    $service = app(WfhService::class);

    $first = $service->submitRequest($employee, [
        'start_date' => '2026-09-10',
        'end_date' => '2026-09-12',
        'reason' => 'First request',
    ]);
    $service->reject($first, $reviewer->id, 'Denied.');

    $second = $service->submitRequest($employee, [
        'start_date' => '2026-09-11',
        'end_date' => '2026-09-13',
        'reason' => 'Resubmission for the same window',
    ]);

    expect($second->status)->toBe('pending');
});

it('cancels a pending request', function () {
    $employee = wfhEmployee();
    $service = app(WfhService::class);

    $request = $service->submitRequest($employee, [
        'start_date' => '2026-09-25',
        'end_date' => '2026-09-25',
        'reason' => 'Working remotely',
    ]);

    $service->cancel($request);

    expect($request->fresh()->status)->toBe('cancelled');
});

it('blocks cancelling a request that is no longer pending', function () {
    $employee = wfhEmployee();
    $reviewer = User::factory()->create();
    $service = app(WfhService::class);

    $request = $service->submitRequest($employee, [
        'start_date' => '2026-09-26',
        'end_date' => '2026-09-26',
        'reason' => 'Working remotely',
    ]);
    $service->approve($request, $reviewer->id);

    expect(fn () => $service->cancel($request->fresh()))
        ->toThrow(DomainException::class, 'Only pending');
});

it('relates wfh requests to the employee', function () {
    $employee = wfhEmployee();

    app(WfhService::class)->submitRequest($employee, [
        'start_date' => '2026-09-28',
        'end_date' => '2026-09-28',
        'reason' => 'Working remotely',
    ]);

    expect($employee->wfhRequests()->count())->toBe(1)
        ->and(WfhRequest::first()->employee->id)->toBe($employee->id);
});
