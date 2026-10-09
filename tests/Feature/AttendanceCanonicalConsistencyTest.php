<?php

use App\Livewire\Attendance\AttendanceTracker;
use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\ShiftSetting;
use App\Services\Attendance\AttendanceCalculator;
use App\Services\Attendance\AttendanceDayRebuilder;
use App\Services\Attendance\PunchTimeline;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * One attendance day, one set of figures: Worked Today, the Today summary,
 * Attendance History and the monthly metrics all come from
 * AttendanceCalculator, and the PunchTimeline counts a break once.
 */
function accEmployee(): Employee
{
    $shift = ShiftSetting::create([
        'name' => 'Consistency IT', 'code' => 'ACC_IT', 'start_time' => '10:30:00', 'end_time' => '19:30:00',
        'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9, 'break_duration' => 60,
    ]);

    return Employee::factory()->create(['shift_id' => $shift->id, 'status' => 'active', 'joining_date' => '2024-01-01']);
}

/** IN 10:25, OUT 11:15 (36-minute break), back IN 11:51 — still open. */
function accOpenDay(Employee $employee, string $date): void
{
    foreach (['10:25:00' => 'face', '11:15:00' => 'id_card', '11:51:00' => 'face'] as $time => $method) {
        AttendancePunch::create([
            'employee_id' => $employee->id, 'punched_at' => "{$date} {$time}", 'punch_date' => $date,
            'method' => $method, 'source' => 'biometric',
        ]);
    }
    app(AttendanceDayRebuilder::class)->rebuild($employee, Carbon::parse($date));
}

/** @return array<string, mixed> the monthHistory row of the date */
function accHistoryRow($component, string $date): array
{
    return collect($component->instance()->monthHistory['rows'])->firstWhere('date', $date);
}

test('a break before a still-open session is counted once, everywhere', function () {
    $this->travelTo(Carbon::parse('2026-07-14 13:00:00'));
    $employee = accEmployee();
    accOpenDay($employee, '2026-07-14');

    $punches = AttendancePunch::where('employee_id', $employee->id)->orderBy('punched_at')->get();
    expect(app(PunchTimeline::class)->process($punches, Carbon::parse('2026-07-14'))['break_minutes'])->toBe(36)
        ->and(app(AttendanceCalculator::class)->forDay($employee, '2026-07-14')->breakMinutes)->toBe(36);

    $component = Livewire::actingAs($employee->user)->test(AttendanceTracker::class);
    expect($component->get('todayCalc')['break_minutes'])->toBe(36)         // Today summary / KPI
        ->and($component->get('punchJourney')['break_minutes'])->toBe(36)   // the timeline drawer
        ->and(accHistoryRow($component, '2026-07-14')['break_minutes'])->toBe(36);   // Attendance History
    $component->assertSee('36m');
});

test('while working, Worked Today and the history show the same live duration', function () {
    $this->travelTo(Carbon::parse('2026-07-14 13:00:00'));
    $employee = accEmployee();
    accOpenDay($employee, '2026-07-14');

    $component = Livewire::actingAs($employee->user)->test(AttendanceTracker::class);

    expect($component->get('todayCalc')['worked_minutes'])->toBe(155)       // 10:25 → 13:00
        ->and(accHistoryRow($component, '2026-07-14')['worked_minutes'])->toBe(155);
});

test('with the checkout missing, Worked Today and the history agree and nothing is counted past the cutoff', function () {
    $this->travelTo(Carbon::parse('2026-07-14 23:55:00'));   // past shift end + 1h, no auto checkout run
    $employee = accEmployee();
    accOpenDay($employee, '2026-07-14');

    $component = Livewire::actingAs($employee->user)->test(AttendanceTracker::class);
    $today = $component->get('todayCalc');
    $history = accHistoryRow($component, '2026-07-14');

    expect($today['missing_checkout'])->toBeTrue()
        ->and($history['worked_minutes'])->toBe($today['worked_minutes'])
        ->and($history['worked_minutes'])->toBe(0)                   // never 13h of "live" time
        ->and($history['missing_checkout'])->toBeTrue();
});

test('after an auto checkout, Worked Today, the history and the monthly figure all read the shift-end span', function () {
    $this->travelTo(Carbon::parse('2026-07-14 12:00:00'));
    $employee = accEmployee();
    accOpenDay($employee, '2026-07-14');
    $this->travelTo(Carbon::parse('2026-07-14 23:20:00'));
    $this->artisan('hrms:auto-checkout')->assertSuccessful();

    $component = Livewire::actingAs($employee->user)->test(AttendanceTracker::class);
    $today = $component->get('todayCalc');
    $history = accHistoryRow($component, '2026-07-14');

    expect($today['worked_minutes'])->toBe(545)                      // 10:25 → 19:30
        ->and($history['worked_minutes'])->toBe(545)
        ->and($history['break_minutes'])->toBe(36)
        ->and($history['auto_checkout'])->toBeTrue()
        ->and($history['missing_checkout'])->toBeFalse()
        ->and($component->get('stats')['hours'])->toBe('9h 5m');       // monthly metrics, same figure
});

test('a past day reads the same in the history and the canonical calculation', function () {
    $this->travelTo(Carbon::parse('2026-07-13 12:00:00'));
    $employee = accEmployee();
    accOpenDay($employee, '2026-07-13');
    AttendancePunch::create(['employee_id' => $employee->id, 'punched_at' => '2026-07-13 19:40:00', 'punch_date' => '2026-07-13', 'method' => 'id_card', 'source' => 'biometric']);
    app(AttendanceDayRebuilder::class)->rebuild($employee, Carbon::parse('2026-07-13'));

    $this->travelTo(Carbon::parse('2026-07-14 10:00:00'));
    $component = Livewire::actingAs($employee->user)->test(AttendanceTracker::class);
    $calc = app(AttendanceCalculator::class)->forDay($employee, '2026-07-13');
    $history = accHistoryRow($component, '2026-07-13');

    expect($history['worked_minutes'])->toBe($calc->workedMinutes)->toBe(555)    // 10:25 → 19:40
        ->and($history['break_minutes'])->toBe($calc->breakMinutes)->toBe(36);
});
