<?php

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\Role;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Models\WfhRequest;
use App\Services\Ai\AiConversation;
use App\Services\Ai\AiDataTools;
use App\Services\Ai\AiIntentRouter;
use App\Services\Approvals\ApprovalQueueService;
use App\Services\Attendance\AttendanceWindowService;
use App\Services\Profile\ProfileCompletionService;
use Illuminate\Support\Carbon;

/**
 * Phase 4 · AI: every figure the assistant states about the user's own data
 * comes from the service the screens use, and approvers get the approval
 * queue, never a second "pending" count. Wed 18 Nov 2026, 19:00, shift
 * 09:00–18:00. Ava (Sales, reports to Mia): on time 2 Nov, late 3 Nov,
 * missing checkout 4 Nov; approved WFH today; one pending WFH request;
 * one pending leave request. Bo is in Support (another department).
 */
function aiUser(string $slug, Department $department, string $name, array $employee = []): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['name' => $name, 'role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null, ...$employee]);

    return $user->fresh();
}

function aiAsk(User $user, string $question): array
{
    $answer = app(AiIntentRouter::class)->answer($user, $question);
    expect($answer)->not->toBeNull("Not answered by Pulse: {$question}");

    return $answer;
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-18 19:00'));
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['shift_start' => '09:00', 'shift_end' => '18:00', 'weekly_off_days' => [Carbon::SATURDAY, Carbon::SUNDAY], 'requires_location' => false, 'requires_photo' => false]);
    AttendanceSetting::flushWeeklyOffCache();
    $shift = ShiftSetting::create(['name' => 'Day', 'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 0, 'is_default' => true]);

    $this->sales = Department::factory()->create(['name' => 'AI Sales']);
    $this->support = Department::factory()->create(['name' => 'AI Support']);
    $this->mia = aiUser('manager', $this->sales, 'Mia Manager', ['shift_id' => $shift->id]);
    $this->hr = aiUser('hr_admin', $this->sales, 'Hana HR', ['shift_id' => $shift->id]);
    $this->ava = aiUser('employee', $this->sales, 'Ava Asker', ['manager_id' => $this->mia->id, 'shift_id' => $shift->id, 'joining_date' => '2026-01-01', 'holiday_calendar' => 'UK']);
    $this->bo = aiUser('employee', $this->support, 'Bo Elsewhere', ['shift_id' => $shift->id, 'joining_date' => '2026-01-01']);

    $e = $this->ava->employee;
    foreach ([['2026-11-02', '08:55', '18:00'], ['2026-11-03', '09:40', '18:05'], ['2026-11-04', '09:00', null]] as [$date, $in, $out]) {
        Attendance::create(['employee_id' => $e->id, 'date' => $date, 'check_in' => Carbon::parse("{$date} {$in}"),
            'check_out' => $out ? Carbon::parse("{$date} {$out}") : null, 'status' => 'on_time', 'is_late' => false, 'work_mode' => 'office']);
    }
    WfhRequest::create(['employee_id' => $e->id, 'start_date' => '2026-11-18', 'end_date' => '2026-11-18', 'reason' => 'Plumber', 'status' => 'approved']);
    WfhRequest::create(['employee_id' => $e->id, 'start_date' => '2026-11-25', 'end_date' => '2026-11-25', 'reason' => 'Delivery', 'status' => 'pending']);
    LeaveRequest::create(['employee_id' => $e->id, 'leave_type_id' => LeaveType::create(['name' => 'Casual'])->id,
        'start_date' => '2026-11-30', 'end_date' => '2026-11-30', 'days' => 1, 'status' => 'pending', 'reason' => 'Errand']);
    LeaveRequest::create(['employee_id' => $this->bo->employee->id, 'leave_type_id' => LeaveType::first()->id,
        'start_date' => '2026-11-30', 'end_date' => '2026-11-30', 'days' => 1, 'status' => 'pending', 'reason' => 'Elsewhere']);
    PublicHoliday::create(['name' => 'Christmas Day', 'date' => '2026-12-25', 'country' => 'UK']);
});

test('my attendance and missing checkout come from the attendance window My Attendance uses', function () {
    $e = $this->ava->employee;
    $window = app(AttendanceWindowService::class);
    $summary = $window->summary($e, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-18'));
    $missing = collect($window->days($e, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-18')))->where('missing_out', true)->count();
    expect([$summary['present'], $summary['late'], $missing])->toBe([3, 1, 1]);

    $answer = aiAsk($this->ava, 'How is my attendance this month?')['content'];
    expect($answer)->toContain("{$summary['present']} of {$summary['scheduled']} scheduled days present ({$summary['late']} late)")
        ->toContain("{$summary['absent']} absent")->toContain('1 day has a missing checkout');

    expect(aiAsk($this->ava, 'Do I have a missing checkout?')['intent'])->toBe('my_attendance');
    // Without view_attendance an employee never gets team figures.
    expect(aiAsk($this->ava, 'How many missing checkouts are there?')['intent'])->toBe('my_attendance');
    // An approver asking about everyone gets today's scoped summary.
    expect(aiAsk($this->mia, 'How many missing checkouts are there?')['intent'])->toBe('attendance_today');
});

test('next holiday, WFH status and profile completeness use the canonical services', function () {
    expect(aiAsk($this->ava, 'When is the next holiday?')['content'])->toContain('Christmas Day');

    $wfh = aiAsk($this->ava, 'What is my WFH status?');
    expect($wfh['intent'])->toBe('my_wfh')
        ->and($wfh['content'])->toContain('You have approved work from home today')->toContain('1 WFH request pending')->toContain('does not authorise');

    $completion = app(ProfileCompletionService::class)->for($this->ava->employee);
    expect(aiAsk($this->ava, 'Is my profile complete?')['content'])->toContain("Your profile is {$completion['percent']}% complete");
});

test('pending questions: own requests for the requester, the approval queue for the approver, within scope', function () {
    expect(aiAsk($this->ava, 'Do I have any pending leave requests?')['content'])->toContain('You have 1 leave request waiting');

    // Mia approves Ava only: Bo (Support) is outside her line.
    $queue = app(ApprovalQueueService::class)->counts($this->mia);
    expect($queue['leave'])->toBe(1);
    $mia = aiAsk($this->mia, 'How many leave requests are pending?');
    expect($mia['intent'])->toBe('approval_queue')->and($mia['content'])->toContain('1 leave request is waiting for your approval');

    // HR's queue counts company-wide, still from the same service.
    $hrQueue = app(ApprovalQueueService::class)->counts($this->hr)['leave'];
    expect(aiAsk($this->hr, 'Leave pending?')['content'])->toContain($hrQueue.' leave request');
});

test('the model context carries no second pending count, only the approval queue', function () {
    $context = app(AiConversation::class)->context($this->mia);

    expect($context['organisation'] ?? [])->not->toHaveKey('pending_leave_requests')->not->toHaveKey('pending_ot_requests')
        ->and($context['waiting_for_my_approval'])->toBe(app(AiDataTools::class)->approvalQueue($this->mia))
        ->and(json_encode($context))->not->toContain('Bo Elsewhere')->not->toContain('Ava Asker');
});

test('"where do I view payroll" points each role at a page it can open', function () {
    $employee = aiAsk($this->ava, 'Where do I view payroll?');
    expect($employee['intent'])->toBe('navigation')
        ->and(collect($employee['links'])->pluck('url'))->not->toContain(route('payroll.overview'));

    expect(collect(aiAsk($this->ava, 'Where do I request leave?')['links'])->pluck('url'))->toContain(route('time-off.my'))
        ->and(collect(aiAsk($this->ava, 'Where do I regularise attendance?')['links'])->pluck('url'))->toContain(route('attendance.my'));
});
