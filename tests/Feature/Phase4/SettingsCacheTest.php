<?php

use App\Livewire\Attendance\AttendanceTracker;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\PublicHoliday;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 · Cache: a settings change shows without a manual cache clear.
 *  - My Attendance's cached month changes key when the working week, the
 *    holiday calendar or the reader's time format changes.
 *  - A long-running queue worker re-reads the working week for every job.
 */
function scMonthKey(User $user): string
{
    $tracker = new AttendanceTracker;
    $method = new ReflectionMethod($tracker, 'monthHistoryCacheKey');

    return $method->invoke($tracker, $user->employee, Carbon::parse('2026-09-01'));
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-16 10:00'));
    AttendanceSetting::query()->delete();
    $this->setting = AttendanceSetting::create(['shift_start' => '09:00', 'shift_end' => '18:00', 'weekly_off_days' => [0, 6]]);
    AttendanceSetting::flushWeeklyOffCache();
    $this->user = User::factory()->create(['time_format' => 'H:i']);
    Employee::factory()->create(['user_id' => $this->user->id, 'status' => 'active']);
    $this->actingAs($this->user);
});

test('the My Attendance month cache key follows settings, holidays and preferences', function () {
    $key = scMonthKey($this->user);
    expect(scMonthKey($this->user))->toBe($key);   // stable while nothing changes

    $this->travel(2)->seconds();
    $this->setting->update(['weekly_off_days' => [0]]);
    $afterWeek = scMonthKey($this->user);
    expect($afterWeek)->not->toBe($key);

    PublicHoliday::create(['name' => 'Cache Day', 'date' => '2026-09-15', 'country' => 'UK']);
    $afterHoliday = scMonthKey($this->user);
    expect($afterHoliday)->not->toBe($afterWeek);

    $this->user->update(['time_format' => 'h:i A']);
    expect(scMonthKey($this->user->fresh()))->not->toBe($afterHoliday);
});

test('a queue job reads the working week saved by another process', function () {
    expect(AttendanceSetting::weeklyOffDays())->toEqualCanonicalizing([0, 6]);   // memoised

    // Another process saves it: no model event fires in this one.
    DB::table('attendance_settings')->update(['weekly_off_days' => json_encode([5])]);
    expect(AttendanceSetting::weeklyOffDays())->toEqualCanonicalizing([0, 6]);   // still the memo

    // The job is serialised, so it reports back through config (same process).
    dispatch(function () {
        config(['testing.weekly_off_seen' => AttendanceSetting::weeklyOffDays()]);
    });

    expect(config('testing.weekly_off_seen'))->toEqualCanonicalizing([5]);
});
