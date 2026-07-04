<?php

use App\Enums\UserRole;
use App\Livewire\TimeOff\AllTimeOff;
use App\Livewire\TimeOff\TeamTimeOff;
use App\Models\Employee;
use App\Models\LeaveAttachment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\LeaveService;
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

test('submitRequest persists multiple categorised attachments and mirrors the first into attachment_path', function () {
    Notification::fake();
    $employee = lamEmployee();
    $type = lamLeaveType();

    $request = app(LeaveService::class)->submitRequest(
        $employee, $type, '2026-09-10', '2026-09-10', 'sick', requestedLeaveStatus: 'unpaid',
        attachments: [
            ['type' => 'medical_certificate', 'path' => 'leave-attachments/med.pdf', 'original_name' => 'med.pdf', 'mime_type' => 'application/pdf', 'size' => 1024],
            ['type' => 'supporting_document', 'path' => 'leave-attachments/sup.jpg', 'original_name' => 'sup.jpg', 'mime_type' => 'image/jpeg', 'size' => 2048],
        ],
    );

    expect($request->attachment_path)->toBe('leave-attachments/med.pdf');
    expect(LeaveAttachment::where('leave_request_id', $request->id)->count())->toBe(2);
    expect(LeaveAttachment::where('leave_request_id', $request->id)->where('type', 'medical_certificate')->exists())->toBeTrue();
});

test('requestMoreInfo moves a pending request to more_info_requested and blocks on non-pending', function () {
    Notification::fake();
    $employee = lamEmployee();
    $reviewer = User::factory()->create();
    $type = lamLeaveType();
    $request = app(LeaveService::class)->submitRequest($employee, $type, '2026-09-11', '2026-09-11', 'trip', requestedLeaveStatus: 'unpaid');

    $fresh = app(LeaveService::class)->requestMoreInfo($request, $reviewer->id, 'Please attach a travel ticket');

    expect($fresh->status)->toBe('more_info_requested');
    expect($fresh->reviewer_comment)->toBe('Please attach a travel ticket');

    expect(fn () => app(LeaveService::class)->requestMoreInfo($fresh, $reviewer->id, 'again'))
        ->toThrow(DomainException::class, 'Only a pending leave request');
});

test('resubmit returns a more_info_requested request to pending and appends a new attachment', function () {
    Notification::fake();
    $employee = lamEmployee();
    $reviewer = User::factory()->create();
    $type = lamLeaveType();
    $request = app(LeaveService::class)->submitRequest($employee, $type, '2026-09-12', '2026-09-12', 'trip', requestedLeaveStatus: 'unpaid');
    app(LeaveService::class)->requestMoreInfo($request, $reviewer->id, 'Need a ticket');

    $fresh = app(LeaveService::class)->resubmit($request->fresh(), 'trip, ticket attached', [
        ['type' => 'travel_ticket', 'path' => 'leave-attachments/ticket.pdf', 'original_name' => 'ticket.pdf', 'mime_type' => 'application/pdf', 'size' => 500],
    ]);

    expect($fresh->status)->toBe('pending');
    expect($fresh->reason)->toBe('trip, ticket attached');
    expect($fresh->reviewer_id)->toBeNull();
    expect(LeaveAttachment::where('leave_request_id', $fresh->id)->where('type', 'travel_ticket')->exists())->toBeTrue();

    expect(fn () => app(LeaveService::class)->resubmit($fresh, 'x'))
        ->toThrow(DomainException::class, 'awaiting more information');
});

test('AllTimeOff can request more information from the review panel', function () {
    Notification::fake();
    $hr = User::factory()->create(['role' => UserRole::HrAdmin]);
    $employee = lamEmployee();
    $type = lamLeaveType();
    $request = app(LeaveService::class)->submitRequest($employee, $type, '2026-09-13', '2026-09-13', 'trip', requestedLeaveStatus: 'unpaid');

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
    $request = app(LeaveService::class)->submitRequest($employee, $type, '2026-09-14', '2026-09-14', 'trip', requestedLeaveStatus: 'unpaid');

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
