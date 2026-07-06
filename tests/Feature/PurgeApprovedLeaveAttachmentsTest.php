<?php

use App\Models\Employee;
use App\Models\LeaveMessage;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Support\Facades\Storage;

test('purges attachments only for leaves approved more than the retention window ago', function () {
    Storage::fake('public');

    $type = LeaveType::create([
        'name' => 'Purge Test', 'code' => 'PURGE'.rand(1000, 9999), 'is_paid' => true,
        'color' => '#000', 'category' => 'annual', 'allow_paid_request' => true, 'allow_unpaid_request' => true,
        'allow_hr_override' => false, 'hr_remark_required' => false, 'allow_carry_forward' => false,
        'carry_forward_limit' => 0, 'allow_encashment' => false, 'is_sandwich_applicable' => false,
        'allow_half_day' => true, 'is_monthly_accrual' => false, 'accrual_days_per_month' => 0,
        'gender_restriction' => 'none', 'probation_restricted' => false, 'notice_period_restricted' => false,
        'max_consecutive_days' => null, 'attachment_required' => false,
    ]);
    $employee = Employee::factory()->create();

    // Old approved leave (40 days ago) — should be purged.
    Storage::disk('public')->put('leave-attachments/old.pdf', 'x');
    Storage::disk('public')->put('leave-attachments/old-msg.pdf', 'y');
    $old = LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => '2026-01-05', 'end_date' => '2026-01-05', 'days' => 1, 'reason' => 'x',
        'requested_leave_status' => 'unpaid', 'status' => 'approved',
        'approved_at' => now()->subDays(40), 'attachment_path' => 'leave-attachments/old.pdf',
    ]);
    LeaveMessage::create(['leave_request_id' => $old->id, 'body' => 'note', 'attachment_path' => 'leave-attachments/old-msg.pdf', 'attachment_name' => 'old-msg.pdf']);

    // Recently approved leave (5 days ago) — should be kept.
    Storage::disk('public')->put('leave-attachments/recent.pdf', 'z');
    $recent = LeaveRequest::create([
        'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => '2026-06-05', 'end_date' => '2026-06-05', 'days' => 1, 'reason' => 'x',
        'requested_leave_status' => 'unpaid', 'status' => 'approved',
        'approved_at' => now()->subDays(5), 'attachment_path' => 'leave-attachments/recent.pdf',
    ]);

    $this->artisan('leave:purge-attachments')->assertSuccessful();

    expect(Storage::disk('public')->exists('leave-attachments/old.pdf'))->toBeFalse();
    expect(Storage::disk('public')->exists('leave-attachments/old-msg.pdf'))->toBeFalse();
    expect(Storage::disk('public')->exists('leave-attachments/recent.pdf'))->toBeTrue();

    expect($old->fresh()->attachment_path)->toBeNull();
    expect(LeaveMessage::where('leave_request_id', $old->id)->first()->attachment_path)->toBeNull();
    expect($recent->fresh()->attachment_path)->toBe('leave-attachments/recent.pdf');
});
