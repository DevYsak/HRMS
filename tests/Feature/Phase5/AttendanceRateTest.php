<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\AttendanceTracker;
use App\Livewire\Profile\MyProfile;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\User;
use App\Services\Ai\AiDataTools;
use App\Services\Attendance\AttendanceRateService;
use App\Services\AttendanceReportBuilder;
use App\Services\EmployeeDashboardService;
use App\Services\People\Employee360Service;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * Phase 5 policy QA-F-079 — one attendance percentage:
 * attended eligible days ÷ eligible working days × 100.
 *
 * Window Mon 5 – Fri 16 Oct 2026, today = Fri 16 Oct (not yet attended),
 * Saturday + Sunday off.
 */
function arEmployee(array $attributes = []): Employee
{
    return Employee::factory()->create([
        'user_id' => User::factory()->create(['role' => UserRole::Employee])->id,
        'status' => 'active', 'joining_date' => '2024-01-08', 'holiday_calendar' => 'UK', ...$attributes,
    ]);
}

function arAttend(Employee $employee, string $date, array $attributes = []): void
{
    $day = Carbon::parse($date);
    Attendance::create(['employee_id' => $employee->id, 'date' => $day->toDateString(), 'check_in' => $day->copy()->setTime(9, 0),
        'check_out' => $day->copy()->setTime(18, 0), 'status' => 'on_time', 'work_mode' => 'office', ...$attributes]);
}

function arLeave(Employee $employee, LeaveType $type, string $date, string $status = 'approved', ?string $paid = null): void
{
    LeaveRequest::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'start_date' => $date, 'end_date' => $date,
        'days' => 1, 'status' => $status, 'reason' => 'Scenario', 'requested_leave_status' => $paid]);
}

function arRate(Employee $employee, string $from = '2026-10-05', string $to = '2026-10-16'): array
{
    return app(AttendanceRateService::class)->forEmployee($employee->fresh(), Carbon::parse($from), Carbon::parse($to));
}

beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-10-16 08:00'));
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['shift_start' => '09:00', 'shift_end' => '18:00', 'weekly_off_days' => [0, 6]]);
    AttendanceSetting::flushWeeklyOffCache();
    $this->paid = LeaveType::create(['name' => 'Casual / Sick', 'is_paid' => true]);
    $this->unpaid = LeaveType::create(['name' => 'Leave Without Pay', 'is_paid' => false, 'category' => 'unpaid']);
});

test('every rule of the definition, in one window', function () {
    $ava = arEmployee();
    arAttend($ava, '2026-10-05');                                          // present
    arAttend($ava, '2026-10-06', ['status' => 'late', 'is_late' => true]); // late counts as attended
    arAttend($ava, '2026-10-07', ['work_mode' => 'wfh']);                  // valid WFH attendance
    arAttend($ava, '2026-10-08', ['status' => 'half_day']);                // half day: ½ attended of 1
    arLeave($ava, $this->paid, '2026-10-09');                              // paid leave: not eligible
    arAttend($ava, '2026-10-10');                                          // worked a Saturday: not eligible
    arLeave($ava, $this->unpaid, '2026-10-12', paid: 'unpaid');            // unpaid leave: eligible, not attended
    // 13 Oct: finalised unexplained absence — eligible, lowers the rate
    arLeave($ava, $this->paid, '2026-10-14', status: 'pending');           // still explainable: not eligible
    PublicHoliday::create(['name' => 'Founders Day', 'date' => '2026-10-15', 'country' => 'UK', 'is_active' => true]);
    // 16 Oct: today, not yet attended — not eligible

    $rate = arRate($ava);

    // attended 1 + 1 + 1 + ½ ; eligible 4 + unpaid 1 + absent 1
    expect([$rate['attended'], $rate['eligible'], $rate['percent']])->toBe([3.5, 6.0, 58]);
});

test('approved paid leave never lowers the rate', function () {
    $ben = arEmployee();
    foreach (['2026-10-05', '2026-10-06', '2026-10-07'] as $date) {
        arAttend($ben, $date);
    }
    foreach (['2026-10-08', '2026-10-09', '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'] as $date) {
        arLeave($ben, $this->paid, $date);
    }

    expect(arRate($ben)['percent'])->toBe(100);
});

test('today counts once attended; future days and days before joining never count', function () {
    $cy = arEmployee(['joining_date' => '2026-10-14']);
    arAttend($cy, '2026-10-14');
    arAttend($cy, '2026-10-16');   // today, attended

    // 14 attended, 15 absent (final), 16 attended — the window running to the 31st adds nothing.
    expect([arRate($cy, '2026-10-01', '2026-10-31')['attended'], arRate($cy, '2026-10-01', '2026-10-31')['eligible']])->toBe([2.0, 3.0])
        ->and(arRate($cy, '2026-10-01', '2026-10-31')['percent'])->toBe(67);
});

test('a group rate weighs every eligible day once', function () {
    $full = arEmployee();
    $half = arEmployee();
    foreach (['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15'] as $date) {
        arAttend($full, $date);
    }
    arAttend($half, '2026-10-12');
    arAttend($half, '2026-10-13');

    $group = app(AttendanceRateService::class)->forEmployees([$full->fresh(), $half->fresh()], Carbon::parse('2026-10-12'), Carbon::parse('2026-10-16'));

    expect($group['employees'][$full->id]['percent'])->toBe(100)
        ->and($group['employees'][$half->id]['percent'])->toBe(50)
        ->and([$group['total']['attended'], $group['total']['eligible'], $group['total']['percent']])->toBe([6.0, 8.0, 75]);
});

test('with no attendance recorded at all there is no rate, not 0%', function () {
    expect(arRate(arEmployee())['percent'])->toBeNull();
});

test('every screen shows the same attendance % for the same person and month', function () {
    // Joined Mon 12 Oct: attended 12–14, absent 15 (final), today (16) not yet attended → 3 of 4 = 75%.
    $dee = arEmployee(['joining_date' => '2026-10-12']);
    foreach (['2026-10-12', '2026-10-13', '2026-10-14'] as $date) {
        arAttend($dee, $date);
    }
    $dee = $dee->fresh();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $monthStart = Carbon::parse('2026-10-01');

    $service = app(AttendanceRateService::class)->forEmployee($dee, $monthStart, Carbon::today())['percent'];
    $profile = Livewire::actingAs($dee->user)->test(MyProfile::class)->viewData('kpis')['attendance'];
    $dashboard = app(EmployeeDashboardService::class)->build($dee->user)['attendance']['percent'];
    $e360 = app(Employee360Service::class)->build($admin, $dee, $monthStart, Carbon::parse('2026-10-31'))['attendance']['rate'];
    $ai = app(AiDataTools::class)->myAttendance($dee->user)['attendance_percent'];
    $myAttendance = Livewire::actingAs($dee->user)->test(AttendanceTracker::class)->get('stats')['rate'];
    $report = collect(app(AttendanceReportBuilder::class)->build('monthly', ['from' => '2026-10-01', 'to' => '2026-10-16'])['rows'])
        ->firstWhere(1, $dee->user->name);

    expect($service)->toBe(75)
        ->and([$profile, $dashboard, $e360, $ai, $myAttendance])->toBe([75, 75, 75, 75, 75])
        ->and(end($report))->toBe('75%');
});
