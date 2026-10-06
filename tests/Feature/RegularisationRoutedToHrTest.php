<?php

use App\Enums\UserRole;
use App\Livewire\ApprovalCenter;
use App\Livewire\Attendance\AllAttendance;
use App\Livewire\Attendance\AttendanceTracker;
use App\Livewire\Attendance\CommandCenter;
use App\Livewire\Attendance\TeamAttendance;
use App\Models\AttendanceRegularisation;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AttendanceRegularisationNotification;
use App\Services\Notifications\NotificationRecipients;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Command Center → Regularisation Requests are routed directly to HR.
 *
 * A holder of "Approve Regularisations (HR)" decides them in one step; line
 * managers still see their team's requests but are shown "Awaiting HR" and
 * cannot approve or reject. The service-level rules live in
 * RegularisationFastPathTest; this file covers the routing and the screens.
 */
function hrRouteEmployee(?User $manager = null): Employee
{
    $user = User::factory()->create(['role' => UserRole::Employee]);

    return Employee::factory()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'manager_id' => $manager?->id,
    ]);
}

function hrRouteRequest(Employee $employee): AttendanceRegularisation
{
    $date = today()->subDays(2)->toDateString();

    return AttendanceRegularisation::create([
        'employee_id' => $employee->id,
        'work_date' => $date,
        'requested_check_in' => "$date 09:00:00",
        'requested_check_out' => "$date 18:00:00",
        'reason' => 'Forgot to punch out at the gate',
        'status' => 'pending',
    ]);
}

test('the HR approval permission is held by HR, not by managers or employees', function () {
    expect(User::factory()->create(['role' => UserRole::HrAdmin])->canApproveRegularisations())->toBeTrue()
        ->and(User::factory()->create(['role' => UserRole::SuperAdmin])->canApproveRegularisations())->toBeTrue()
        ->and(User::factory()->create(['role' => UserRole::Manager])->canApproveRegularisations())->toBeFalse()
        ->and(User::factory()->create(['role' => UserRole::Employee])->canApproveRegularisations())->toBeFalse();
});

test('a new request defaults to the HR review stage', function () {
    expect(hrRouteRequest(hrRouteEmployee())->fresh()->stage)->toBe('hr_review');
});

test('an employee submission notifies HR and not the line manager', function () {
    Notification::fake();
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = hrRouteEmployee($manager);

    Livewire::actingAs($employee->user)->test(AttendanceTracker::class)
        ->set('regDate', today()->subDays(2)->toDateString())
        ->set('regCheckIn', '09:00')
        ->set('regCheckOut', '18:00')
        ->set('regReason', 'Forgot to punch out at the gate')
        ->call('submitRegularisation')
        ->assertHasNoErrors();

    expect(AttendanceRegularisation::where('employee_id', $employee->id)->value('stage'))->toBe('hr_review');
    Notification::assertSentTo($hr, AttendanceRegularisationNotification::class);
    Notification::assertNotSentTo($manager, AttendanceRegularisationNotification::class);
});

test('the HR approvers never include the employee themselves', function () {
    $hrUser = User::factory()->create(['role' => UserRole::HrAdmin]);
    $hrEmployee = Employee::factory()->create(['user_id' => $hrUser->id, 'status' => 'active']);
    $otherHr = User::factory()->create(['role' => UserRole::HrAdmin]);

    $approvers = app(NotificationRecipients::class)->regularisationApprovers($hrEmployee)->pluck('id');

    expect($approvers)->toContain($otherHr->id)
        ->not->toContain($hrUser->id);
});

test('a manager sees Awaiting HR in the Command Center and cannot decide', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $employee = hrRouteEmployee($manager);
    $reg = hrRouteRequest($employee);

    Livewire::actingAs($manager)->test(CommandCenter::class)
        ->set('tab', 'regularisation')
        ->assertSee($employee->user->name)
        ->assertSee('Awaiting HR')
        ->call('approveOne', 'regularisation', $reg->id);

    expect($reg->fresh()->status)->toBe('pending');
});

test('HR approves from the Command Center and the correction is applied', function () {
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = hrRouteEmployee();
    $reg = hrRouteRequest($employee);

    Livewire::actingAs($hr)->test(CommandCenter::class)
        ->set('tab', 'regularisation')
        ->assertDontSee('Awaiting HR')
        ->call('approveOne', 'regularisation', $reg->id);

    expect($reg->fresh()->status)->toBe('approved')
        ->and($reg->fresh()->applied_via)->toBe('hr_direct');
});

test('a manager cannot quick-approve or review from the attendance screens', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $reg = hrRouteRequest(hrRouteEmployee($manager));

    Livewire::actingAs($manager)->test(AllAttendance::class)
        ->call('quickApproveRegularisation', $reg->id)
        ->assertForbidden();

    Livewire::actingAs($manager)->test(TeamAttendance::class)
        ->call('openReviewModal', $reg->id)
        ->assertForbidden();

    expect($reg->fresh()->status)->toBe('pending');
});

test('the Approval Center lists regularisations to HR only', function () {
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $employee = hrRouteEmployee($manager);
    hrRouteRequest($employee);

    Livewire::actingAs($manager)->test(ApprovalCenter::class)
        ->call('setFilter', 'attendance')
        ->assertDontSee($employee->user->name);

    Livewire::actingAs(User::factory()->create(['role' => UserRole::HrAdmin]))->test(ApprovalCenter::class)
        ->call('setFilter', 'attendance')
        ->assertSee($employee->user->name);
});
