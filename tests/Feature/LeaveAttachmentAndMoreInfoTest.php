<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\AllTimeOff;
use App\Livewire\TimeOff\TeamTimeOff;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\LeaveAttachment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveRequestNotification;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

// Unique helper names — this codebase runs all Pest files in one process, and
// LeaveServiceTest.php already declares leaveEmployee()/paidLeaveType(); reusing
// those names here would fatal on "Cannot redeclare function".
function lamEmployee(array $attrs = []): Employee
{
    return Employee::factory()->create($attrs);
}

function lamLeaveType(array $overrides = []): LeaveType
{
    return LeaveType::create(array_merge([
        'name' => 'LAM Test Leave',
        'code' => 'LAM'.rand(1000, 9999),
        'is_paid' => true,
        'color' => '#1DB77A',
        'category' => 'annual',
        'allow_paid_request' => true,
        'allow_unpaid_request' => true,
        'allow_hr_override' => true,
        'hr_remark_required' => false,
        'allow_carry_forward' => false,
        'carry_forward_limit' => 0,
        'allow_encashment' => false,
        'is_sandwich_applicable' => false,
        'allow_half_day' => true,
        'is_monthly_accrual' => false,
        'accrual_days_per_month' => 0,
        'gender_restriction' => 'none',
        'probation_restricted' => false,
        'notice_period_restricted' => false,
        'max_consecutive_days' => null,
        'attachment_required' => false,
    ], $overrides));
}

test('submitRequest persists the single supporting attachment and mirrors it into attachment_path', function () {
    Notification::fake();
    $employee = lamEmployee();
    $type = lamLeaveType();

    // 2026-09-10 is a Thursday (a working day).
    $request = app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-09-10', '2026-09-10', 'sick', requestedLeaveStatus: 'unpaid',
        attachments: [
            ['type' => 'supporting_document', 'path' => 'leave-attachments/sup.pdf', 'original_name' => 'sup.pdf', 'mime_type' => 'application/pdf', 'size' => 1024],
        ],
    );

    expect($request->attachment_path)->toBe('leave-attachments/sup.pdf');
    expect(LeaveAttachment::where('leave_request_id', $request->id)->count())->toBe(1);
});

test('submitRequest blocks a non-working start or end date', function () {
    Notification::fake();
    $employee = lamEmployee();
    $type = lamLeaveType();

    // The rule is now the company's configured week rather than Carbon's
    // hardcoded Sat+Sun, so the week this test assumes is stated explicitly.
    // Without it the default is Sunday-only, under which Saturday is a working
    // day and the first assertion would rightly not throw.
    AttendanceSetting::query()->delete();
    AttendanceSetting::create([
        'shift_start' => '09:00', 'shift_end' => '18:00',
        'weekly_off_days' => [Carbon::SATURDAY, Carbon::SUNDAY],
    ]);
    AttendanceSetting::flushWeeklyOffCache();

    // 2026-09-12 is a Saturday, 2026-09-13 a Sunday.
    expect(fn () => app(LeaveService::class)->submitRequest($employee, $type, '2026-09-12', '2026-09-12', 'x', requestedLeaveStatus: 'unpaid'))
        ->toThrow(DomainException::class, 'non-working day');

    expect(fn () => app(LeaveService::class)->submitRequest($employee, $type, '2026-09-13', '2026-09-13', 'x', requestedLeaveStatus: 'unpaid'))
        ->toThrow(DomainException::class, 'non-working day');

    // A Friday→Monday range is fine — the weekend inside it is simply not counted.
    $ok = app(LeaveService::class)->submitRequest($employee, $type, '2026-09-11', '2026-09-14', 'trip', requestedLeaveStatus: 'unpaid');
    expect($ok->status)->toBe('pending');
});

test('requestMoreInfo moves a pending request to more_info_requested and blocks on non-pending', function () {
    Notification::fake();
    $employee = lamEmployee();
    $reviewer = User::factory()->create();
    $type = lamLeaveType();
    // 2026-09-11 is a Friday.
    $request = app(LeaveService::class)->submitRequest($employee, $type, '2026-09-11', '2026-09-11', 'trip', requestedLeaveStatus: 'unpaid');

    $fresh = app(LeaveService::class)->requestMoreInfo($request, $reviewer->id, 'Please attach a travel ticket');

    expect($fresh->status)->toBe('more_info_requested');
    expect($fresh->reviewer_comment)->toBe('Please attach a travel ticket');

    expect(fn () => app(LeaveService::class)->requestMoreInfo($fresh, $reviewer->id, 'again'))
        ->toThrow(DomainException::class, 'Only a pending leave request');
});

test('postMessage runs the two-way clarification loop', function () {
    Notification::fake();
    $employee = lamEmployee();
    $reviewerUser = User::factory()->create();
    $employeeUser = $employee->user;
    $type = lamLeaveType();
    // 2026-09-14 is a Monday.
    $request = app(LeaveService::class)->submitRequest($employee, $type, '2026-09-14', '2026-09-14', 'trip', requestedLeaveStatus: 'unpaid');

    // Reviewer asks for clarification → more_info_requested.
    $afterReviewer = app(LeaveService::class)->postMessage($request, $reviewerUser, 'Need a ticket', 'leave-attachments/ask.pdf', 'ask.pdf');
    expect($afterReviewer->status)->toBe('more_info_requested');

    // Employee replies with a message + attachment → back to pending.
    $afterEmployee = app(LeaveService::class)->postMessage($request->fresh(), $employeeUser, 'Ticket attached', 'leave-attachments/ticket.pdf', 'ticket.pdf');
    expect($afterEmployee->status)->toBe('pending');
    expect($request->messages()->count())->toBe(2);

    // An empty message with no attachment is rejected.
    expect(fn () => app(LeaveService::class)->postMessage($request->fresh(), $employeeUser, '   ', null, null))
        ->toThrow(DomainException::class, 'Add a message or an attachment');
});

test('an employee can message HR on a rejected request without changing its status', function () {
    Notification::fake();
    $employee = lamEmployee();
    $employeeUser = $employee->user;
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $type = lamLeaveType();
    // 2026-09-18 is a Friday.
    $request = app(LeaveService::class)->submitRequest($employee, $type, '2026-09-18', '2026-09-18', 'trip', requestedLeaveStatus: 'unpaid');
    $request->update(['status' => 'rejected', 'reviewer_comment' => 'Not enough balance', 'reviewer_id' => $hr->id]);

    $fresh = app(LeaveService::class)->postMessage($request->fresh(), $employeeUser, 'Please reconsider — attached proof', 'leave-attachments/proof.pdf', 'proof.pdf');

    // Status is untouched (appeal channel only), but the message is stored and HR/admin are notified.
    expect($fresh->status)->toBe('rejected');
    expect($request->messages()->count())->toBe(1);
    Notification::assertSentTo($hr, LeaveRequestNotification::class);
});

test('AllTimeOff can post a conversation message from the review panel', function () {
    Notification::fake();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = lamEmployee();
    $type = lamLeaveType();
    // 2026-09-15 is a Tuesday.
    $request = app(LeaveService::class)->submitRequest($employee, $type, '2026-09-15', '2026-09-15', 'trip', requestedLeaveStatus: 'unpaid');

    Livewire::actingAs($hr)->test(AllTimeOff::class)
        ->call('viewRequest', $request->id)
        ->set('panelMessage', 'Please attach supporting documents')
        ->call('postPanelMessage')
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe('more_info_requested');
    expect($request->messages()->count())->toBe(1);
});

test('AllTimeOff can request more information from the review panel', function () {
    Notification::fake();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = lamEmployee();
    $type = lamLeaveType();
    // 2026-09-16 is a Wednesday.
    $request = app(LeaveService::class)->submitRequest($employee, $type, '2026-09-16', '2026-09-16', 'trip', requestedLeaveStatus: 'unpaid');

    Livewire::actingAs($hr)->test(AllTimeOff::class)
        ->call('viewRequest', $request->id)
        ->set('panelReviewComment', 'Please attach supporting documents')
        ->call('requestMoreInfo')
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe('more_info_requested');
});

test('TeamTimeOff can request more information from the review modal', function () {
    Notification::fake();
    $manager = User::factory()->create(['role' => UserRole::Manager]);
    $employee = lamEmployee(['manager_id' => $manager->id]);
    $type = lamLeaveType();
    // 2026-09-17 is a Thursday.
    $request = app(LeaveService::class)->submitRequest($employee, $type, '2026-09-17', '2026-09-17', 'trip', requestedLeaveStatus: 'unpaid');

    Livewire::actingAs($manager)->test(TeamTimeOff::class)
        ->call('selectRequest', $request->id)
        ->set('reviewer_comment', 'Please clarify the reason')
        ->call('requestMoreInfo')
        ->assertHasNoErrors();

    expect($request->fresh()->status)->toBe('more_info_requested');
});

test('a leave attachment exposes the right icon and type label', function () {
    $employee = lamEmployee();
    $type = lamLeaveType();
    $request = LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => '2026-09-15', 'end_date' => '2026-09-15', 'days' => 1,
        'reason' => 'x', 'requested_leave_status' => 'unpaid', 'status' => 'pending',
    ]);
    $att = LeaveAttachment::create([
        'leave_request_id' => $request->id, 'type' => 'medical_certificate',
        'path' => 'x/y.pdf', 'original_name' => 'y.pdf', 'mime_type' => 'application/pdf', 'size' => 100,
    ]);

    expect($att->typeLabel())->toBe('Medical Certificate');
    expect($att->icon())->toBe('document-text');
    expect($att->isPdf())->toBeTrue();
});
