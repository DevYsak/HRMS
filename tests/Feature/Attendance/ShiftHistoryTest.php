<?php

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Models\User;
use App\Services\Attendance\ShiftHistory;
use App\Services\Attendance\ShiftResolver;
use Illuminate\Support\Carbon;

/*
 * A past day is judged against the shift that applied on it. Before, every
 * day was judged against today's shift, so someone moved from the 13:00 UK
 * shift to the 10:30 IT shift had every earlier day re-marked hours late on
 * the next re-sync.
 */

function historyShift(string $name, string $start, string $end, int $grace = 6): ShiftSetting
{
    return ShiftSetting::create(['name' => $name, 'start_time' => $start, 'end_time' => $end,
        'grace_minutes' => $grace, 'standard_hours' => 9, 'break_duration' => 60]);
}

/**
 * Date the audit entry a change just wrote. The audit log takes its time from
 * the database clock (CURRENT_TIMESTAMP), which test time travel does not move.
 */
function dateLastAudit(string $at): void
{
    AuditLog::query()->whereKey(AuditLog::max('id'))->update(['created_at' => $at]);
}

function lateOn(Employee $employee, string $date, string $firstIn): bool
{
    return app(ShiftResolver::class)->resolve($employee->fresh(), $date)->isLate(Carbon::parse("{$date} {$firstIn}"));
}

beforeEach(function () {
    $this->uk = historyShift('UK Sales Shift', '13:00', '22:00');
    $this->it = historyShift('IT Shift', '10:30', '19:30');
    $this->actingAs($this->hr = User::factory()->create(['name' => 'Shivani HR']));
});

test('a move to another shift applies from the day it was made, never to the days before', function () {
    $this->travelTo(Carbon::parse('2026-10-01 10:00'));
    $employee = Employee::factory()->create(['shift_id' => $this->uk->id, 'status' => 'active']);

    $this->travelTo(Carbon::parse('2026-11-05 11:00'));
    $employee->update(['shift_id' => $this->it->id]);
    dateLastAudit(now()->toDateTimeString());

    $this->travelTo(Carbon::parse('2026-11-10 12:00'));
    $resolver = app(ShiftResolver::class);

    expect($resolver->resolve($employee->fresh(), '2026-11-04')->name)->toBe('UK Sales Shift')
        ->and($resolver->resolve($employee->fresh(), '2026-11-05')->name)->toBe('IT Shift')
        ->and($resolver->resolve($employee->fresh(), '2026-11-10')->name)->toBe('IT Shift')
        // 12:58 on the UK shift is on time — not 2½ hours late against IT.
        ->and(lateOn($employee, '2026-11-04', '12:58'))->toBeFalse()
        ->and(lateOn($employee, '2026-11-06', '10:40'))->toBeTrue();
});

test('a first shift assignment is data entry: the days before it use that shift', function () {
    $this->travelTo(Carbon::parse('2026-10-01 10:00'));
    $employee = Employee::factory()->create(['shift_id' => null, 'status' => 'active']);

    $this->travelTo(Carbon::parse('2026-11-05 11:00'));
    $employee->update(['shift_id' => $this->it->id]);
    dateLastAudit(now()->toDateTimeString());

    $this->travelTo(Carbon::parse('2026-11-10 12:00'));

    expect(app(ShiftResolver::class)->resolve($employee->fresh(), '2026-10-20')->name)->toBe('IT Shift');
});

test('an edit of a shift\'s timings does not reach back to the days before it', function () {
    $this->travelTo(Carbon::parse('2026-10-01 10:00'));
    $this->it->update(['start_time' => '10:00', 'end_time' => '19:00']);
    dateLastAudit(now()->toDateTimeString());
    $employee = Employee::factory()->create(['shift_id' => $this->it->id, 'status' => 'active']);

    $this->travelTo(Carbon::parse('2026-11-06 09:00'));
    $this->it->update(['start_time' => '10:30', 'end_time' => '19:30']);
    dateLastAudit(now()->toDateTimeString());

    $this->travelTo(Carbon::parse('2026-11-10 12:00'));

    // 10:20 is 14 minutes late against the 10:00 start that applied on 5 Nov,
    // and on time against the 10:30 start from 6 Nov.
    expect(lateOn($employee, '2026-11-05', '10:20'))->toBeTrue()
        ->and(app(ShiftResolver::class)->resolve($employee->fresh(), '2026-11-05')->lateMinutes(Carbon::parse('2026-11-05 10:20')))->toBe(14)
        ->and(lateOn($employee, '2026-11-07', '10:20'))->toBeFalse()
        ->and($this->it->fresh()->start_time)->toStartWith('10:30');
});

test('with no recorded change every day uses the current shift, as before', function () {
    $employee = Employee::factory()->create(['shift_id' => $this->uk->id, 'status' => 'active']);
    $resolver = app(ShiftResolver::class);

    foreach (['2026-01-15', '2026-07-01', now()->toDateString()] as $date) {
        expect($resolver->resolve($employee->fresh(), $date)->name)->toBe('UK Sales Shift');
    }
});

test('the changes in a period list the shift and timings before and after, and who made them', function () {
    $this->travelTo(Carbon::parse('2026-10-01 10:00'));
    $employee = Employee::factory()->create(['shift_id' => $this->uk->id, 'status' => 'active']);

    $this->travelTo(Carbon::parse('2026-11-05 11:00'));
    $employee->update(['shift_id' => $this->it->id]);
    dateLastAudit(now()->toDateTimeString());

    $this->travelTo(Carbon::parse('2026-11-08 09:00'));
    $this->it->update(['start_time' => '11:00', 'end_time' => '20:00']);
    dateLastAudit(now()->toDateTimeString());

    $changes = app(ShiftHistory::class)->changesBetween(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'), [$employee->id]);

    expect($changes)->toHaveCount(2)
        ->and($changes[0])->toMatchArray(['type' => 'reassigned', 'date' => '2026-11-05', 'employee_id' => $employee->id, 'by' => 'Shivani HR'])
        ->and($changes[0]['before']->name)->toBe('UK Sales Shift')
        ->and(ShiftHistory::timingLabel($changes[0]['before']))->toBe('13:00 – 22:00 · 6 min grace')
        ->and(ShiftHistory::timingLabel($changes[0]['after']))->toBe('10:30 – 19:30 · 6 min grace')
        ->and($changes[1])->toMatchArray(['type' => 'timing', 'date' => '2026-11-08', 'shift_id' => $this->it->id])
        ->and(ShiftHistory::timingLabel($changes[1]['before']))->toBe('10:30 – 19:30 · 6 min grace')
        ->and(ShiftHistory::timingLabel($changes[1]['after']))->toBe('11:00 – 20:00 · 6 min grace')
        // Outside the period: nothing.
        ->and(app(ShiftHistory::class)->changesBetween(Carbon::parse('2026-12-01'), Carbon::parse('2026-12-31'), [$employee->id]))->toBe([]);
});
