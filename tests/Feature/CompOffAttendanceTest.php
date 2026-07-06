<?php

use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\LeaveService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

test('creditCompOff adds a comp-off balance the employee can spend', function () {
    $employee = Employee::factory()->create();

    $balance = app(LeaveService::class)->creditCompOff($employee, Carbon::parse('2026-07-01'), 1.0);

    expect($balance->leaveType->category)->toBe('comp_off');
    expect((float) $balance->comp_off_credits)->toBe(1.0);
    expect((float) $balance->allocated_days)->toBe(1.0);
});

test('an approved comp-off leave day is shown as leave (not absent) on My Attendance', function () {
    $user = User::factory()->create();
    $employee = Employee::factory()->create(['user_id' => $user->id]);

    // Credit a comp-off day, then take it as an approved leave earlier this month.
    $balance = app(LeaveService::class)->creditCompOff($employee, Carbon::parse(now()->startOfMonth()->toDateString()), 1.0);
    $leaveDay = now()->startOfMonth()->addDays(3);

    LeaveRequest::create([
        'employee_id' => $employee->id,
        'leave_type_id' => $balance->leave_type_id,
        'start_date' => $leaveDay->toDateString(),
        'end_date' => $leaveDay->toDateString(),
        'days' => 1,
        'reason' => 'comp-off day',
        'requested_leave_status' => 'paid',
        'status' => 'approved',
        'approved_at' => now(),
    ]);

    $component = Livewire::actingAs($user)->test(AttendanceTracker::class);

    $day = collect($component->get('calendarDays'))->firstWhere('date', $leaveDay->toDateString());

    expect($day)->not->toBeNull();
    expect($day['status'])->toBe('leave');
});
