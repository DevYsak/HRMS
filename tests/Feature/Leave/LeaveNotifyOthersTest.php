<?php

use App\Livewire\TimeOff\LeaveRequestDetail;
use App\Livewire\TimeOff\MyTimeOff;
use App\Livewire\TimeOff\RequestLeave;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\NotificationSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\LeaveRequestNotification;
use App\Services\LeaveService;
use App\Services\Notifications\NotificationCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
 * Notify Others (25 Nov 2026): on a leave application the employee may
 * choose extra people to tell, besides the manager, approvers and HR who
 * are always told. They get the application and every decision on it, as
 * people told about it — never asked to decide. Who may choose is a role
 * permission (notify_others_on_leave), which also covers HR's "Also
 * notify" on a decision; nobody is ever told twice.
 */

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-25 10:00:00'));
    $this->type = LeaveType::create(['name' => 'Casual', 'is_paid' => false, 'allow_unpaid_request' => true]);

    $hrRole = Role::where('slug', 'hr_admin')->firstOrFail();
    $this->hr = User::factory()->create(['role' => $hrRole->legacyBucket(), 'role_id' => $hrRole->id, 'name' => 'Hema HR']);
    $this->colleague = User::factory()->create(['name' => 'Rohan Teammate', 'email' => 'rohan@example.com']);
    $this->lead = User::factory()->create(['name' => 'Asha Lead', 'email' => 'asha@example.com']);
    $this->employee = Employee::factory()->create(['user_id' => User::factory()->create(['name' => 'Yogesh Employee'])->id,
        'status' => 'active', 'manager_id' => null, 'joining_date' => '2024-01-08']);
});

/** The leave form (Leave V2 off) filled in for Tue 1 Dec, unpaid. */
function noForm()
{
    return Livewire::actingAs(test()->employee->user)->test(MyTimeOff::class)
        ->call('openRequestModal')
        ->set('leave_type_id', (string) test()->type->id)
        ->set('start_date', '2026-12-01')
        ->set('end_date', '2026-12-01')
        ->set('requested_leave_status', 'unpaid')
        ->set('reason', 'Family function');
}

function noApproved(User $user): int
{
    return Notification::sent($user, LeaveRequestNotification::class,
        fn ($n) => str_contains($n->toArray($user)['title'], 'Leave Approved'))->count();
}

test('the employee chooses people to tell; they get the application and the decision, never as a decider', function () {
    Notification::fake();

    noForm()
        ->assertSeeHtml('data-notify-recipients')
        ->assertSee('your manager and HR are always told')
        ->set('recipientSearch', 'Rohan')
        ->assertSee('Rohan Teammate')
        ->call('addRecipient', $this->colleague->id)
        ->call('addRecipient', $this->lead->id)
        ->call('addRecipient', $this->employee->user_id)   // themself: never offered, ignored
        ->call('submitRequest')
        ->assertHasNoErrors();

    $request = LeaveRequest::where('employee_id', $this->employee->id)->sole();
    expect($request->notify_user_ids)->toEqualCanonicalizing([$this->colleague->id, $this->lead->id]);

    foreach ([$this->colleague, $this->lead] as $user) {
        Notification::assertSentTo($user, LeaveRequestNotification::class, fn ($n) => $n->notificationRole() === 'recipient');
    }

    app(LeaveService::class)->reviewRequest($request->fresh(), ['leave_type_id' => $this->type->id, 'start_date' => '2026-12-01',
        'end_date' => '2026-12-01', 'reason' => 'Family function', 'is_half_day' => false], 'approved', $this->hr->id, null);

    expect(noApproved($this->colleague))->toBe(1)
        ->and(noApproved($this->lead))->toBe(1);
    Notification::assertSentTo($this->colleague, LeaveRequestNotification::class,
        fn ($n) => $n->notificationRole() === 'recipient' && str_contains($n->toArray($this->colleague)['title'], 'Leave Approved'));
});

test('HR sees who the employee chose, and "Also notify" never tells them twice but names them in the audit', function () {
    Notification::fake();
    $request = app(LeaveService::class)->submitRequest($this->employee->fresh(), $this->type, '2026-12-01', '2026-12-01', 'Family function',
        requestedLeaveStatus: 'unpaid', notifyUserIds: [$this->colleague->id, $this->employee->user_id]);

    expect($request->notify_user_ids)->toBe([$this->colleague->id]);

    Livewire::actingAs($this->hr)->test(LeaveRequestDetail::class, ['leaveRequest' => $request])
        ->assertSeeHtml('data-employee-notify-others')
        ->assertSee('Rohan Teammate')
        ->call('confirm', 'approve')
        ->call('addRecipient', $this->colleague->id)
        ->call('addRecipient', $this->lead->id)
        ->call('approve')
        ->assertHasNoErrors();

    expect(noApproved($this->colleague))->toBe(1)
        ->and(noApproved($this->lead))->toBe(1)
        ->and(AuditLog::where('event', 'LEAVE_RECIPIENTS_NOTIFIED')->sole()->new_values['recipients'])->toHaveCount(2);
});

test('someone excluded on Notifications & Email is never told, even when chosen', function () {
    Notification::fake();
    app(NotificationCatalog::class)->sync();
    NotificationSetting::for(LeaveRequestNotification::class)->update(['exclude_user_ids' => [$this->lead->id]]);
    NotificationSetting::flushCache();

    app(LeaveService::class)->submitRequest($this->employee->fresh(), $this->type, '2026-12-01', '2026-12-01', 'Family function',
        requestedLeaveStatus: 'unpaid', notifyUserIds: [$this->colleague->id, $this->lead->id]);

    Notification::assertSentTo($this->colleague, LeaveRequestNotification::class);
    Notification::assertNotSentTo($this->lead, LeaveRequestNotification::class);
});

test('without the permission the choice is not offered, cannot be added, and nothing is stored', function () {
    $role = Role::where('slug', 'employee')->firstOrFail();
    $role->permissions()->detach(Permission::where('key', 'notify_others_on_leave')->value('id'));
    Cache::forget("role_{$role->id}_permission_keys");
    Cache::forget("role_{$role->id}_permission_scopes");
    Notification::fake();

    noForm()
        ->assertDontSeeHtml('data-notify-recipients')
        ->set('notifyUserIds', [$this->colleague->id])   // a tampered request
        ->call('submitRequest')
        ->assertHasNoErrors();

    expect(LeaveRequest::where('employee_id', $this->employee->id)->sole()->notify_user_ids)->toBeNull();
    Notification::assertNotSentTo($this->colleague, LeaveRequestNotification::class);

    noForm()->call('addRecipient', $this->colleague->id)->assertForbidden();
});

test('the permission is in Roles & Permissions and held by every role that applies for leave', function () {
    $permission = Permission::where('key', 'notify_others_on_leave')->sole();

    expect($permission->module)->toBe('Leave Management')
        ->and((bool) $permission->is_scoped)->toBeFalse();

    foreach (['employee', 'manager', 'hr_admin', 'director', 'department_head', 'coordinator', 'finance'] as $slug) {
        expect(Role::where('slug', $slug)->firstOrFail()->hasPermission('notify_others_on_leave'))->toBeTrue("{$slug} should hold notify_others_on_leave");
    }
});

test('the request drawer offers the same choice and never the employee themself', function () {
    Livewire::actingAs($this->employee->user)->test(RequestLeave::class)
        ->call('addRecipient', $this->employee->user_id)
        ->call('addRecipient', $this->colleague->id)
        ->call('addRecipient', $this->colleague->id)
        ->assertSet('notifyUserIds', [$this->colleague->id])
        ->call('removeRecipient', $this->colleague->id)
        ->assertSet('notifyUserIds', []);
});
