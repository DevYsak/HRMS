<?php

use App\Enums\UserRole;
use App\Livewire\NotificationsPage;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveRequestNotification;
use App\Notifications\ProbationDueNotification;
use App\Notifications\ProfileChangeRequestNotification;
use App\Services\LeaveService;
use App\Services\Profile\ProfileChangeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Phase 4 notification follow-up: queued notices keep the status of the
 * moment they were sent; cancellations and profile requests notify the
 * right people; daily reminders do not repeat; the bell clears.
 */
beforeEach(function () {
    $this->dept = Department::factory()->create();
    $this->manager = User::factory()->create(['role' => UserRole::Manager, 'name' => 'Mia Manager']);
    Employee::factory()->create(['user_id' => $this->manager->id, 'department_id' => $this->dept->id, 'status' => 'active', 'manager_id' => null]);
    $this->hr = User::factory()->create(['role' => UserRole::HrAdmin, 'name' => 'Hana HR']);
    Employee::factory()->create(['user_id' => $this->hr->id, 'department_id' => $this->dept->id, 'status' => 'active', 'manager_id' => null]);
    $this->user = User::factory()->create(['role' => UserRole::Employee, 'name' => 'Eve Employee']);
    $this->employee = Employee::factory()->create(['user_id' => $this->user->id, 'department_id' => $this->dept->id, 'status' => 'active',
        'manager_id' => $this->manager->id, 'joining_date' => '2024-01-08']);
    $this->leave = LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => LeaveType::create(['name' => 'Casual'])->id,
        'start_date' => Carbon::today()->addWeek(), 'end_date' => Carbon::today()->addWeek(), 'days' => 1, 'status' => 'pending', 'reason' => 'Errand']);
});

test('a queued leave notice keeps the status it was sent with', function () {
    $notice = (new LeaveRequestNotification($this->leave->fresh(['employee.user', 'leaveType'])))->forRole('manager');

    $this->leave->update(['status' => 'approved']);   // decided before the worker ran

    expect($notice->toArray($this->manager)['title'])->toBe('New Leave Request');
});

test('a comment on a decided leave is a message, never the decision again', function () {
    Notification::fake();
    $this->leave->update(['status' => 'approved']);

    app(LeaveService::class)->postMessage($this->leave->fresh(), $this->hr, 'Enjoy the day off');

    Notification::assertSentTo($this->user, LeaveRequestNotification::class,
        fn ($n) => $n->event === 'message' && $n->toArray($this->user)['title'] === 'New Message on a Leave Request');
});

test('a cancellation notifies the other side', function () {
    Notification::fake();
    app(LeaveService::class)->cancelRequest($this->leave->fresh(), 'Plans changed', $this->user);

    Notification::assertSentTo($this->manager, LeaveRequestNotification::class,
        fn ($n) => str_contains($n->toArray($this->manager)['body'], 'Eve Employee cancelled their'));
    Notification::assertNotSentTo($this->user, LeaveRequestNotification::class);

    // HR cancelling someone's leave tells the employee.
    $other = LeaveRequest::create(['employee_id' => $this->employee->id, 'leave_type_id' => $this->leave->leave_type_id,
        'start_date' => Carbon::today()->addWeeks(2), 'end_date' => Carbon::today()->addWeeks(2), 'days' => 1, 'status' => 'pending', 'reason' => 'Other']);
    app(LeaveService::class)->cancelRequest($other, 'Duplicate', $this->hr);
    Notification::assertSentTo($this->user, LeaveRequestNotification::class, fn ($n) => $n->leaveRequest->id === $other->id);
});

test('a profile change request reaches its approvers and the decision reaches the employee', function () {
    Notification::fake();
    $this->employee->update(['address' => 'Old Address']);

    $request = app(ProfileChangeService::class)->requestChange($this->employee->fresh(), 'address', 'New Address', $this->user, 'Moved');
    Notification::assertSentTo($this->hr, ProfileChangeRequestNotification::class);
    Notification::assertNotSentTo($this->user, ProfileChangeRequestNotification::class);

    app(ProfileChangeService::class)->approve($request, $this->hr, 'Seen proof');
    Notification::assertSentTo($this->user, ProfileChangeRequestNotification::class,
        fn ($n) => $n->toArray($this->user)['title'] === 'Profile Change Approved');
});

test('the overdue-probation digest is sent once a week, not every day', function () {
    Notification::fake();
    Employee::factory()->create(['status' => 'probation', 'probation_end_date' => Carbon::today()->subDays(3), 'probation_confirmed_at' => null, 'manager_id' => null]);

    $this->artisan('hrms:check-probation-expiry')->assertSuccessful();
    $this->artisan('hrms:check-probation-expiry')->assertSuccessful();

    Notification::assertSentToTimes($this->hr, ProbationDueNotification::class, 1);
});

test('reading on the Notifications page clears the bell count cache', function () {
    Cache::put('notif_count_'.$this->user->id, 5, 30);

    Livewire::actingAs($this->user)->test(NotificationsPage::class)->call('markAllRead');

    expect(Cache::has('notif_count_'.$this->user->id))->toBeFalse();
});
