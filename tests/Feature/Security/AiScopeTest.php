<?php

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Role;
use App\Models\User;
use App\Services\Ai\AiDataTools;
use Illuminate\Support\Carbon;

/**
 * The AI Assistant answers from the same scoped services as the dashboards
 * (Phase 1 AI scope audit): a Department Head's answers cover only their
 * department, even when asked about another department by id or name.
 */
function aisUser(string $slug, Department $department): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null]);
    if ($slug === 'department_head') {
        $department->update(['head_id' => $user->id]);
    }

    return $user->fresh();
}

function aisStaff(Department $department): Employee
{
    $user = User::factory()->create(['role_id' => Role::where('slug', 'employee')->value('id')]);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null, 'joining_date' => '2024-01-01']);
    Attendance::create(['employee_id' => $employee->id, 'date' => today()->toDateString(), 'check_in' => now()->setTime(9, 0), 'status' => 'on_time', 'work_mode' => 'office']);
    $type = LeaveType::firstOrCreate(['code' => 'AIS'], ['name' => 'AI Scope Leave', 'category' => 'other', 'color' => '#999999', 'allow_paid_request' => true]);
    LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => today()->addDays(4)->toDateString(), 'end_date' => today()->addDays(4)->toDateString(), 'days' => 1, 'is_half_day' => false, 'reason' => 'x', 'status' => 'pending', 'requested_leave_status' => 'paid']);

    return $employee;
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 14:00:00'));
    $this->own = Department::factory()->create(['name' => 'Own Ops']);
    $this->other = Department::factory()->create(['name' => 'Other Bravo']);
    $this->head = aisUser('department_head', $this->own);
    aisStaff($this->own);
    aisStaff($this->other);
    aisStaff($this->other);
});

test('attendance answers cover only the department head\'s department', function () {
    $tools = app(AiDataTools::class);

    $mine = $tools->attendanceToday($this->head);
    $asked = $tools->attendanceToday($this->head, $this->other->id);

    // One colleague in the department (the user is never counted); the two
    // in the other department never appear, even when asked for by id.
    expect($mine['own'])->toBeFalse()
        ->and($mine['summary']['total'])->toBe(1)
        ->and($mine['summary']['present'])->toBe(1)
        ->and($asked['summary']['total'])->toBe(0);
});

test('another department cannot be looked up by name', function () {
    expect(app(AiDataTools::class)->departmentNamed($this->head, 'Other Bravo'))->toBeNull()
        ->and(app(AiDataTools::class)->departmentNamed($this->head, 'Own Ops')?->id)->toBe($this->own->id);
});

test('the approval queue counts only requests in reach', function () {
    expect(app(AiDataTools::class)->approvalQueue($this->head)['leave'] ?? null)->toBe(1);
});
