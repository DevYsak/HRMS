<?php

use App\Livewire\Attendance\AttendanceTracker;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Phase 4 · Date/time: My Attendance shows every time in the reader's own
 * 12/24-hour preference. A 24-hour user (every account created before
 * 11 Oct) saw "14:05" on the dashboard but "02:05 PM" in the timeline, and
 * the history table was always 24-hour whatever the preference.
 */
function mtfUser(string $timeFormat): User
{
    $user = User::factory()->create(['time_format' => $timeFormat]);
    $shift = ShiftSetting::create(['name' => 'Day '.$timeFormat, 'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 0]);
    $employee = Employee::factory()->create(['user_id' => $user->id, 'status' => 'active', 'manager_id' => null, 'shift_id' => $shift->id, 'joining_date' => '2026-01-01']);
    Attendance::create(['employee_id' => $employee->id, 'date' => '2026-10-15', 'check_in' => Carbon::parse('2026-10-15 09:05'),
        'check_out' => Carbon::parse('2026-10-15 14:05'), 'status' => 'on_time', 'work_mode' => 'office']);

    return $user->fresh();
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-16 10:00'));
    AttendanceSetting::query()->delete();
    AttendanceSetting::create(['shift_start' => '09:00', 'shift_end' => '18:00', 'weekly_off_days' => [0, 6]]);
    AttendanceSetting::flushWeeklyOffCache();
});

test('a 24-hour reader sees 24-hour times in the history and the timeline', function () {
    Livewire::actingAs(mtfUser('H:i'))->test(AttendanceTracker::class)
        ->assertOk()
        ->assertSee('14:05')->assertDontSee('02:05 PM');
});

test('a 12-hour reader sees AM / PM everywhere, including the history table', function () {
    Livewire::actingAs(mtfUser('h:i A'))->test(AttendanceTracker::class)
        ->assertOk()
        ->assertSee('02:05 PM')->assertDontSee('>14:05<', false);
});
