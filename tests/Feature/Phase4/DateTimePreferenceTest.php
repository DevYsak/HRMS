<?php

use App\Livewire\Attendance\TeamAttendance;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Notifications\MissingCheckoutNotification;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * Phase 4 · Date/time: notifications are written in the RECIPIENT's date
 * format and 12/24-hour clock (not "M d, Y" in one notice and "d M Y" in the
 * next), and the interactive attendance pages follow the reader's.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14 20:00'));
    $this->employee = Employee::factory()->create(['status' => 'active', 'manager_id' => null, 'joining_date' => '2024-01-08']);
    $this->attendance = Attendance::create(['employee_id' => $this->employee->id, 'date' => '2026-10-14',
        'check_in' => Carbon::parse('2026-10-14 14:05'), 'check_out' => null, 'status' => 'on_time', 'work_mode' => 'office']);
});

test('a notification uses each recipient\'s own date and time format', function () {
    $iso24 = User::factory()->create(['date_format' => 'Y-m-d', 'time_format' => 'H:i']);
    $uk12 = User::factory()->create(['date_format' => 'd M Y', 'time_format' => 'h:i A']);
    $notice = new MissingCheckoutNotification($this->attendance->fresh(['employee.user']));

    expect($notice->toArray($iso24)['body'])->toContain('2026-10-14')->toContain('14:05')
        ->and($notice->toArray($uk12)['body'])->toContain('14 Oct 2026')->toContain('02:05 PM');
});

test('Team Attendance shows times in the reader\'s 12/24-hour format', function () {
    $role = Role::where('slug', 'hr_admin')->firstOrFail();
    $reader = User::factory()->create(['role' => $role->legacyBucket(), 'role_id' => $role->id, 'time_format' => 'H:i']);
    Employee::factory()->create(['user_id' => $reader->id, 'status' => 'active', 'manager_id' => null]);
    $this->employee->update(['manager_id' => $reader->id]);

    $html = Livewire::actingAs($reader)->test(TeamAttendance::class)->html();
    expect($html)->not->toContain('02:05 PM');

    $reader->update(['time_format' => 'h:i A']);
    expect(Livewire::actingAs($reader->fresh())->test(TeamAttendance::class)->html())->not->toMatch('/>\s*14:05\s*</');
});
