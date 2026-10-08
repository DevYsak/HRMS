<?php

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveRequestNotification;
use App\Services\LeaveService;
use Illuminate\Support\Facades\Notification;

/**
 * D2 (8 Oct 2026): the reporting approver's decision on leave is final — no
 * routine second HR approval — and an approval must never reach the employee
 * as "Leave Rejected". HR keeps override, correction and escalation.
 */
beforeEach(function () {
    Notification::fake();
});

function lafEmployee(?User $manager = null): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active',
        'manager_id' => $manager?->id,
    ]);
}

function lafLeave(Employee $employee, string $status = 'pending'): LeaveRequest
{
    $type = LeaveType::create([
        'name' => 'Casual Leave', 'code' => 'LAF'.random_int(1000, 9999), 'category' => 'annual',
        'is_paid' => false, 'color' => '#10b981', 'allow_paid_request' => false, 'allow_unpaid_request' => true,
    ]);
    $day = now()->addDays(10);
    while ($day->isWeekend()) {
        $day = $day->addDay();
    }

    return LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => $day, 'end_date' => $day, 'days' => 1, 'reason' => 'Family event',
        'requested_leave_status' => 'unpaid', 'status' => $status,
    ]);
}

function lafForm(LeaveRequest $request): array
{
    return [
        'leave_type_id' => $request->leave_type_id,
        'start_date' => $request->start_date->format('Y-m-d'),
        'end_date' => $request->end_date->format('Y-m-d'),
        'reason' => $request->reason,
        'is_half_day' => false,
    ];
}

/** The payload a notification renders for its recipient role. */
function lafPayload(LeaveRequest $request, string $role = 'employee'): array
{
    return (new LeaveRequestNotification($request->fresh(['employee.user', 'leaveType'])))->forRole($role)->toArray($request->employee->user);
}

test('a manager approval is final and is never sent as a rejection', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $employee = lafEmployee($manager);
    $request = lafLeave($employee);

    app(LeaveService::class)->reviewRequest($request, lafForm($request), 'approved', $manager->id, 'Enjoy');

    $fresh = $request->fresh();
    expect($fresh->status)->toBe('approved')
        ->and($fresh->approved_at)->not->toBeNull()
        ->and($fresh->reviewer_id)->toBe($manager->id);

    Notification::assertSentTo($employee->user, LeaveRequestNotification::class, function (LeaveRequestNotification $n) use ($employee) {
        $payload = $n->toArray($employee->user);

        return $payload['title'] === 'Leave Approved ✓' && ! str_contains($payload['title'].$payload['body'], 'reject');
    });
});

test('a manager approval no longer queues the request for a second HR approval', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $request = lafLeave(lafEmployee($manager));

    app(LeaveService::class)->reviewRequest($request, lafForm($request), 'approved', $manager->id);

    Notification::assertNotSentTo($hr, LeaveRequestNotification::class);
    expect(LeaveRequest::where('status', 'pending_hr')->count())->toBe(0);
});

test('a rejection is still reported as a rejection', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $request = lafLeave(lafEmployee($manager));

    app(LeaveService::class)->reviewRequest($request, lafForm($request), 'rejected', $manager->id, 'Team cover is short');

    expect($request->fresh()->status)->toBe('rejected')
        ->and(lafPayload($request)['title'])->toBe('Leave Rejected');
});

test('a request still parked with HR from before D2 is never described as rejected', function () {
    $request = lafLeave(lafEmployee(), 'pending_hr');

    expect(lafPayload($request, 'employee')['title'])->toBe('Leave Awaiting HR')
        ->and(lafPayload($request, 'hr_admin')['title'])->toBe('Leave Awaiting HR Decision')
        ->and(lafPayload($request, 'employee')['body'])->not->toContain('reject');
});

test('an unknown status falls back to a neutral update, not a rejection', function () {
    $request = lafLeave(lafEmployee());
    $request->forceFill(['status' => 'some_future_state']);

    $payload = (new LeaveRequestNotification($request->load(['employee.user', 'leaveType'])))->forRole('employee')->toArray($request->employee->user);

    expect($payload['title'])->toBe('Leave Request Updated');
});

test('HR can still finish a request that was parked with HR before D2', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $request = lafLeave(lafEmployee(), 'pending_hr');

    app(LeaveService::class)->hrApproveRequest($request, $hr->id, 'approved', 'OK');

    expect($request->fresh()->status)->toBe('approved');
});

test('an approval whose dates hold no working day is refused, not posted as a zero-day debit', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $request = lafLeave(lafEmployee($manager));
    $saturday = now()->next('Saturday')->addWeek();
    $form = ['start_date' => $saturday->format('Y-m-d'), 'end_date' => $saturday->addDay()->format('Y-m-d')] + lafForm($request);

    expect(fn () => app(LeaveService::class)->reviewRequest($request, $form, 'approved', $manager->id))
        ->toThrow(DomainException::class, 'no working days');

    expect($request->fresh()->status)->toBe('pending');
});

test('HR keeps the override: an approved request can be changed by HR', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $request = lafLeave(lafEmployee($manager));

    app(LeaveService::class)->reviewRequest($request, lafForm($request), 'approved', $manager->id);
    app(LeaveService::class)->reviewRequest($request->fresh(), lafForm($request), 'rejected', $hr->id, 'Override: clash with audit week');

    expect($request->fresh()->status)->toBe('rejected')
        ->and($request->fresh()->reviewer_id)->toBe($hr->id);
});
