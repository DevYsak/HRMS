<?php

use App\Livewire\TimeOff\MyTimeOff;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

// ─── Helpers ──────────────────────────────────────────────────────────────────

function timeOffEmployee(): Employee
{
    $user = User::factory()->create(['role' => 'employee']);

    return Employee::factory()->create(['user_id' => $user->id]);
}

function timeOffLeaveType(array $overrides = []): LeaveType
{
    return LeaveType::create(array_merge([
        'name' => 'Annual Leave '.Str::random(4),
        'code' => 'AL'.Str::random(3),
        'category' => 'annual',
        'is_paid' => true,
        'color' => '#10b981',
        'allow_paid_request' => true,
        'allow_unpaid_request' => false,
    ], $overrides));
}

function timeOffBalance(Employee $employee, LeaveType $type, array $overrides = []): LeaveBalance
{
    return LeaveBalance::create(array_merge([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'year' => now()->year,
        'allocated_days' => 12,
        'used_days' => 4,
        'carried_forward_days' => 0,
        'encashed_days' => 0,
        'comp_off_credits' => 0,
    ], $overrides));
}

// ─── Tests ────────────────────────────────────────────────────────────────────

test('my time off page renders summary cards, statistics and calendar', function () {
    $employee = timeOffEmployee();
    $this->actingAs($employee->user);

    $type = timeOffLeaveType();
    timeOffBalance($employee, $type);

    $request = LeaveRequest::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $type->id,
        'start_date' => now()->addDays(2),
        'end_date' => now()->addDays(2),
        'days' => 1,
        'reason' => 'Personal errand',
        'requested_leave_status' => 'paid',
        'approved_leave_status' => 'paid',
        'status' => 'pending',
    ]);

    Livewire::test(MyTimeOff::class)
        ->assertSee($type->name)
        ->assertSee('Total Leaves Allocated')
        ->assertSee('Leaves Used')
        ->assertSee('Leaves Remaining')
        ->assertSee('Pending Requests')
        ->assertSee('Leave Calendar')
        ->assertSee('Request ID')
        ->assertSee('LR-'.str_pad((string) $request->id, 4, '0', STR_PAD_LEFT));
});

test('search filters the leave requests table', function () {
    $employee = timeOffEmployee();
    $this->actingAs($employee->user);

    $doctorType = timeOffLeaveType(['name' => 'Doctor Visit Leave '.Str::random(4)]);
    $familyType = timeOffLeaveType(['name' => 'Family Function Leave '.Str::random(4)]);
    timeOffBalance($employee, $doctorType);
    timeOffBalance($employee, $familyType);

    $doctorRequest = LeaveRequest::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $doctorType->id,
        'start_date' => now()->addDays(1),
        'end_date' => now()->addDays(1),
        'days' => 1,
        'reason' => 'Doctor appointment',
        'requested_leave_status' => 'paid',
        'approved_leave_status' => 'paid',
        'status' => 'pending',
    ]);

    $familyRequest = LeaveRequest::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $familyType->id,
        'start_date' => now()->addDays(5),
        'end_date' => now()->addDays(5),
        'days' => 1,
        'reason' => 'Family function',
        'requested_leave_status' => 'paid',
        'approved_leave_status' => 'paid',
        'status' => 'pending',
    ]);

    $doctorRequestId = 'LR-'.str_pad((string) $doctorRequest->id, 4, '0', STR_PAD_LEFT);
    $familyRequestId = 'LR-'.str_pad((string) $familyRequest->id, 4, '0', STR_PAD_LEFT);

    Livewire::test(MyTimeOff::class)
        ->assertSee($doctorRequestId)
        ->assertSee($familyRequestId)
        ->set('search', 'Doctor')
        ->assertSee($doctorRequestId)
        ->assertDontSee($familyRequestId);
});

test('sortBy toggles sort field and direction', function () {
    $employee = timeOffEmployee();
    $this->actingAs($employee->user);

    Livewire::test(MyTimeOff::class)
        ->assertSet('sortField', 'created_at')
        ->assertSet('sortDirection', 'desc')
        ->call('sortBy', 'days')
        ->assertSet('sortField', 'days')
        ->assertSet('sortDirection', 'asc')
        ->call('sortBy', 'days')
        ->assertSet('sortDirection', 'desc');
});

test('calendar month navigation updates the calendar label', function () {
    $employee = timeOffEmployee();
    $this->actingAs($employee->user);

    $currentLabel = now()->format('F Y');
    $nextLabel = now()->addMonth()->format('F Y');

    Livewire::test(MyTimeOff::class)
        ->assertViewHas('calendarLabel', $currentLabel)
        ->call('nextCalendarMonth')
        ->assertViewHas('calendarLabel', $nextLabel)
        ->call('goToCurrentCalendarMonth')
        ->assertViewHas('calendarLabel', $currentLabel);
});
