<?php

use App\Enums\UserRole;
use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\User;
use App\Models\WarningLetter;
use Illuminate\Support\Facades\Notification;

function lateWarningEmployee(int $lateDays): Employee
{
    $employee = Employee::factory()->create([
        'user_id' => User::factory()->create()->id,
        'status' => 'active',
    ]);

    foreach (range(0, $lateDays - 1) as $i) {
        $d = today()->startOfMonth()->addDays($i);
        Attendance::create([
            'employee_id' => $employee->id,
            'date' => $d,
            'check_in' => $d->copy()->setTime(10, 0),
            'status' => 'late',
            'is_late' => true,
            'late_minutes' => 45,
            'work_mode' => 'office',
        ]);
    }

    return $employee;
}

beforeEach(function () {
    Notification::fake();
    User::factory()->create(['role' => UserRole::SuperAdmin]);
});

test('reaching the late threshold issues a verbal warning letter once, idempotently', function () {
    $employee = lateWarningEmployee(3);

    $this->artisan('hrms:issue-late-warnings')->assertSuccessful();

    $letter = WarningLetter::where('employee_id', $employee->id)->first();
    expect($letter)->not->toBeNull()
        ->and($letter->warning_type)->toBe('verbal')
        ->and($letter->status)->toBe('issued')
        ->and($letter->reason)->toContain('3 late arrivals');

    // The performance timeline carries the disciplinary event.
    $this->assertDatabaseHas('performance_timelines', [
        'employee_id' => $employee->id,
        'event_type' => 'warning_issued',
    ]);

    // Running again the same month must NOT duplicate the letter.
    $this->artisan('hrms:issue-late-warnings')->assertSuccessful();
    expect(WarningLetter::where('employee_id', $employee->id)->count())->toBe(1);
})->skip(fn () => today()->day < 3, 'Needs at least 3 elapsed days in the current month.');

test('below the configured threshold no letter is issued', function () {
    AttendanceSetting::create(['shift_start' => '09:00:00', 'shift_end' => '18:00:00', 'late_warning_threshold' => 5]);
    $employee = lateWarningEmployee(3);

    $this->artisan('hrms:issue-late-warnings')->assertSuccessful();

    expect(WarningLetter::where('employee_id', $employee->id)->exists())->toBeFalse();
})->skip(fn () => today()->day < 3, 'Needs at least 3 elapsed days in the current month.');

test('a repeat offence in a later month escalates the chain instead of stacking verbals', function () {
    $employee = lateWarningEmployee(3);

    // Last month's attendance-discipline verbal already on file.
    WarningLetter::create([
        'employee_id' => $employee->id,
        'issued_by' => User::where('role', UserRole::SuperAdmin)->first()->id,
        'warning_type' => 'verbal',
        'reason' => 'Attendance discipline: 4 late arrivals in '.today()->subMonthNoOverflow()->format('F Y').' (threshold 3)',
        'issue_date' => today()->subMonthNoOverflow()->toDateString(),
        'status' => 'issued',
    ]);

    $this->artisan('hrms:issue-late-warnings')->assertSuccessful();

    $latest = WarningLetter::where('employee_id', $employee->id)->orderByDesc('id')->first();
    expect($latest->warning_type)->toBe('first_written')
        ->and($latest->previous_warning_id)->not->toBeNull();
})->skip(fn () => today()->day < 3, 'Needs at least 3 elapsed days in the current month.');
