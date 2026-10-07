<?php

use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Models\ShiftSetting;
use Illuminate\Support\Carbon;

/**
 * attendance:rebuild-punch-timelines — previews by default, and with --apply
 * rewrites only processed attendance and summaries from the canonical
 * timeline. Raw punches are never touched; regularised, payroll-settled and
 * risky days are skipped and listed.
 *
 * Clock: Thursday 15 October 2026, 09:00.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 09:00:00'));
    $this->shift = ShiftSetting::create([
        'name' => 'Rebuild Shift', 'start_time' => '10:30:00', 'end_time' => '19:30:00',
        'break_duration' => 60, 'grace_minutes' => 5, 'standard_hours' => 9, 'ot_threshold_hours' => 9,
    ]);
});

function rbtEmployee(): Employee
{
    return Employee::factory()->create(['status' => 'active', 'shift_id' => test()->shift->id, 'joining_date' => '2024-01-08', 'employee_code' => fake()->unique()->numberBetween(5000, 9999)]);
}

/** @param  array<int, array{0: string, 1: string, 2?: ?string}>  $punches */
function rbtPunches(Employee $employee, string $date, array $punches): void
{
    foreach ($punches as $p) {
        AttendancePunch::factory()->create([
            'employee_id' => $employee->id, 'punched_at' => $date.' '.$p[0], 'punch_date' => $date,
            'method' => $p[1], 'direction' => $p[2] ?? null, 'source' => 'biometric',
        ]);
    }
}

/** A row as the old engine sync wrote it: raw first → last punch, its own break. */
function rbtStaleRow(Employee $employee, string $date, string $in, ?string $out, float $hours, int $break = 0): Attendance
{
    AttendanceDailySummary::create([
        'employee_id' => $employee->id, 'employee_code' => $employee->employee_code, 'date' => $date,
        'first_punch' => "$date $in", 'last_punch' => $out ? "$date $out" : null,
        'break_minutes' => $break, 'working_hours' => $hours, 'raw_punch_count' => 4, 'synced_at' => now(),
    ]);

    return Attendance::create([
        'employee_id' => $employee->id, 'date' => $date, 'check_in' => "$date $in", 'check_out' => $out ? "$date $out" : null,
        'total_hours' => $hours, 'break_minutes' => $break, 'status' => 'on_time', 'work_mode' => 'office',
    ]);
}

function rbtSnapshot(): array
{
    return [
        'attendance' => Attendance::orderBy('id')->get()->map->only(['check_in', 'check_out', 'total_hours', 'break_minutes', 'status'])->toArray(),
        'summaries' => AttendanceDailySummary::orderBy('id')->get()->map->only(['first_punch', 'last_punch', 'working_hours', 'break_minutes'])->toArray(),
        'punches' => AttendancePunch::orderBy('id')->get()->map->only(['id', 'punched_at', 'method', 'direction', 'source', 'updated_at'])->toArray(),
    ];
}

test('the dry run previews every change and writes nothing', function () {
    $employee = rbtEmployee();
    // A stray card before arrival and a duplicate face burst: the old row
    // started at the card tap.
    rbtPunches($employee, '2026-10-14', [['09:10:00', 'id_card', 'in'], ['10:30:01', 'face'], ['10:30:48', 'face', 'out'], ['19:30:00', 'id_card']]);
    rbtStaleRow($employee, '2026-10-14', '09:10:00', '19:30:00', 10.33, 15);
    $before = rbtSnapshot();

    $this->artisan('attendance:rebuild-punch-timelines')
        ->expectsOutputToContain('DRY RUN — nothing changed')
        ->expectsOutputToContain('10:30:48')
        ->assertSuccessful();

    expect(rbtSnapshot())->toEqual($before)
        ->and(AuditLog::where('event', 'ATTENDANCE_TIMELINE_REBUILT')->count())->toBe(0);
});

test('--apply rebuilds attendance and summaries from the timeline, raw punches untouched', function () {
    $employee = rbtEmployee();
    rbtPunches($employee, '2026-10-14', [
        ['09:10:00', 'id_card', 'in'],                          // stray card
        ['10:30:01', 'face'], ['10:30:48', 'face', 'out'],      // burst, device tag wrong
        ['13:00:00', 'id_card'], ['14:00:00', 'face'],          // 60m break
        ['19:30:00', 'id_card'],
    ]);
    $row = rbtStaleRow($employee, '2026-10-14', '09:10:00', '19:30:00', 9.33, 15);
    $punches = rbtSnapshot()['punches'];

    $this->artisan('attendance:rebuild-punch-timelines', ['--apply' => true])
        ->expectsOutputToContain('Applied (1 written)')
        ->assertSuccessful();

    $row->refresh();
    $summary = AttendanceDailySummary::first();

    expect($row->check_in->format('H:i:s'))->toBe('10:30:48')
        ->and($row->check_out->format('H:i:s'))->toBe('19:30:00')
        ->and((float) $row->total_hours)->toBe(8.98)            // 539 whole minutes, breaks not deducted
        ->and((int) $row->break_minutes)->toBe(60)
        ->and($row->is_late)->toBeFalse()
        ->and($summary->first_punch->format('H:i:s'))->toBe('10:30:48')
        ->and((float) $summary->working_hours)->toBe(8.98)
        ->and((int) $summary->break_minutes)->toBe(60)
        ->and(rbtSnapshot()['punches'])->toEqual($punches)
        ->and(AuditLog::where('event', 'ATTENDANCE_TIMELINE_REBUILT')->count())->toBe(1);
});

test('filters limit the run to one employee and one date', function () {
    $a = rbtEmployee();
    $b = rbtEmployee();
    foreach ([$a, $b] as $e) {
        foreach (['2026-10-13', '2026-10-14'] as $date) {
            rbtPunches($e, $date, [['10:30:00', 'face'], ['10:30:30', 'face'], ['19:30:00', 'id_card']]);
            rbtStaleRow($e, $date, '10:30:00', '19:30:00', 9.0);
        }
    }

    $this->artisan('attendance:rebuild-punch-timelines', ['--apply' => true, '--employee' => (string) $a->employee_code, '--date' => '2026-10-14'])
        ->assertSuccessful();

    $changed = Attendance::get()->filter(fn ($r) => $r->check_in->format('H:i:s') === '10:30:30');

    expect($changed)->toHaveCount(1)
        ->and($changed->first()->employee_id)->toBe($a->id)
        ->and($changed->first()->date->toDateString())->toBe('2026-10-14');

    $this->artisan('attendance:rebuild-punch-timelines', ['--apply' => true, '--from' => '2026-10-13', '--to' => '2026-10-14'])
        ->assertSuccessful();

    expect(Attendance::get()->every(fn ($r) => $r->check_in->format('H:i:s') === '10:30:30'))->toBeTrue();
});

test('regularised, payroll-settled and no-IN days are skipped, never guessed', function () {
    $regularised = rbtEmployee();
    rbtPunches($regularised, '2026-10-14', [['10:40:00', 'face'], ['17:00:00', 'id_card']]);
    Attendance::create([
        'employee_id' => $regularised->id, 'date' => '2026-10-14', 'check_in' => '2026-10-14 10:30:00', 'check_out' => '2026-10-14 19:30:00',
        'original_check_in' => '2026-10-14 10:40:00', 'original_check_out' => '2026-10-14 17:00:00',
        'is_regularized' => true, 'total_hours' => 9, 'status' => 'on_time', 'work_mode' => 'office',
    ]);

    $paid = rbtEmployee();
    rbtPunches($paid, '2026-10-14', [['10:30:00', 'face'], ['10:30:30', 'face'], ['19:30:00', 'id_card']]);
    rbtStaleRow($paid, '2026-10-14', '10:30:00', '19:30:00', 9.0);
    $payroll = Payroll::create(['month' => 'October', 'year' => 2026, 'status' => 'finalized', 'cycle' => 'cycle_a', 'total_payout' => 0]);
    Payslip::create(['payroll_id' => $payroll->id, 'employee_id' => $paid->id, 'gross_salary' => 0, 'total_deductions' => 0, 'net_salary' => 0]);

    $strayOnly = rbtEmployee();
    rbtPunches($strayOnly, '2026-10-14', [['12:00:00', 'id_card']]);
    rbtStaleRow($strayOnly, '2026-10-14', '12:00:00', null, 0);

    $before = rbtSnapshot();

    $this->artisan('attendance:rebuild-punch-timelines', ['--apply' => true])
        ->expectsOutputToContain('SKIP: regularised')
        ->expectsOutputToContain('SKIP: inside approved payroll')
        ->expectsOutputToContain('SKIP: no valid Face IN')
        ->expectsOutputToContain('3 skipped')
        ->assertSuccessful();

    expect(rbtSnapshot())->toEqual($before);
});

test('a malformed date is refused', function () {
    $this->artisan('attendance:rebuild-punch-timelines', ['--date' => '14/10/2026'])->assertFailed();
});
