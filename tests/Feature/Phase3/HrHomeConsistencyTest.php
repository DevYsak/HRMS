<?php

use App\Livewire\ManagerDashboard;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OtRequest;
use App\Models\Role;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Ai\AiDataTools;
use App\Services\Approvals\ApprovalQueueService;
use App\Services\Dashboards\OrganisationOverview;
use App\Services\Dashboards\PeopleOperationsService;
use App\Services\Navigation\Sidebar;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * P3-04 + P3-05 on the HR / Super Admin home. One department, Wed 14 Oct
 * 2026 10:00, shift 09:00–18:00: Ava in at 09:40 (late), Ben in at 08:58.
 */
function hhUser(string $slug, Department $department, string $name, array $employee = []): User
{
    $role = Role::where('slug', $slug)->firstOrFail();
    $user = User::factory()->create(['name' => $name, 'role' => $role->legacyBucket(), 'role_id' => $role->id]);
    Employee::factory()->create(['user_id' => $user->id, 'department_id' => $department->id, 'status' => 'active', 'manager_id' => null, 'joining_date' => '2026-01-01', ...$employee]);

    return $user->fresh();
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 10:00'));
    $shift = ShiftSetting::create(['name' => 'Day', 'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 0, 'is_default' => true]);
    $this->dept = Department::factory()->create(['name' => 'HH Operations']);
    $this->manager = hhUser('manager', $this->dept, 'Mia Manager', ['shift_id' => $shift->id]);
    $this->hr = hhUser('hr_admin', $this->dept, 'Hana HR', ['shift_id' => $shift->id]);
    $common = ['manager_id' => $this->manager->id, 'shift_id' => $shift->id];
    $this->ava = hhUser('employee', $this->dept, 'Ava Late', $common)->employee;
    $this->ben = hhUser('employee', $this->dept, 'Ben Ontime', $common)->employee;
    foreach ([[$this->ava, '09:40'], [$this->ben, '08:58'], [$this->manager->employee, '08:30'], [$this->hr->employee, '08:45']] as [$e, $in]) {
        Attendance::create(['employee_id' => $e->id, 'date' => '2026-10-14', 'check_in' => Carbon::parse("2026-10-14 {$in}"), 'status' => 'on_time', 'work_mode' => 'office']);
    }
});

test('P3-05: the HR home, People Operations, Team View and AI give the same attendance today', function () {
    $people = [$this->ava->id, $this->ben->id];

    $home = app(OrganisationOverview::class)->for($this->hr)->attendanceToday();
    $ops = app(PeopleOperationsService::class)->overview($this->hr, 'view_attendance')['attendance'];
    $ai = app(AiDataTools::class)->attendanceToday($this->hr, $this->dept->id)['summary'];

    // Same people (the viewer's own record excluded everywhere), same answer: 3 in, 1 late (Ava).
    expect([$home['present'], $home['late']])->toBe([3, 1])
        ->and([$ops['present'], $ops['late']])->toBe([3, 1])
        ->and([$ai['present'], $ai['late']])->toBe([3, 1]);

    // Team View over the manager's two reports agrees on the same two people.
    Livewire::actingAs($this->manager)->test(ManagerDashboard::class)
        ->assertViewHas('presentCount', 2)->assertViewHas('lateCount', 1);
    expect($ops['rows'][$this->ava->id]['day']->isLate)->toBeTrue()
        ->and($ops['rows'][$this->ben->id]['day']->isLate)->toBeFalse();
});

test('P3-04: "waiting for your decision", "open in your scope" and "needs more info" are separate and labelled', function () {
    $casual = LeaveType::create(['name' => 'Casual']);
    $leave = fn (Employee $e, string $status) => LeaveRequest::create(['employee_id' => $e->id, 'leave_type_id' => $casual->id, 'start_date' => '2026-10-20', 'end_date' => '2026-10-20', 'days' => 1, 'status' => $status, 'reason' => 'x']);
    $leave($this->ava, 'pending');               // with the manager — HR can decide too
    $leave($this->ben, 'pending_hr');            // HR's stage
    $leave($this->ben, 'more_info_requested');   // back with the employee
    OtRequest::create(['employee_id' => $this->ava->id, 'work_date' => '2026-10-13', 'start_time' => '18:00', 'end_time' => '19:00', 'requested_hours' => 1, 'reason' => 'x', 'status' => 'pending']);

    $queue = app(ApprovalQueueService::class);
    $mine = $queue->counts($this->hr);
    $data = OrganisationOverview::viewData($this->hr);

    expect($data['myApprovals'])->toBe($mine)
        ->and(array_sum($data['myApprovals']))->toBe($queue->total($this->hr))
        ->and($data['moreInfo']['leave'])->toBe(1)
        ->and($data['approvals']['leave_manager'] + $data['approvals']['leave_hr'])->toBe(2);

    // The Approvals badge in the menu is the same number.
    $badge = collect(app(Sidebar::class)->groups($this->hr))->flatMap(fn ($g) => $g['items'])->firstWhere('route', 'attendance.command-center')['badge'] ?? null;
    expect((int) $badge)->toBe(min(9, $queue->total($this->hr)));

    $this->actingAs($this->hr)->get(route('dashboard'))->assertOk()
        ->assertSee('Waiting for your decision')->assertSee('Open requests in your scope')
        ->assertSee('Needs more info — back with the employee')->assertDontSee('Waiting for a decision');
});
