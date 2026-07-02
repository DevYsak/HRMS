<?php

use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\Employee;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.biometric_app.url' => 'http://engine.test']);
});

/** A /api/dashboard payload shaped like the real Python engine returns. */
function fakeDashboard(array $table): void
{
    Http::fake([
        '*/api/dashboard*' => Http::response(['table' => $table], 200),
    ]);
}

test('it pulls engine attendance and upserts a daily summary', function () {
    $yogesh = Employee::factory()->create(['employee_code' => 17, 'manager_id' => null]);

    fakeDashboard([
        // Yogesh's real-shaped row: 4h33m work, 2h37m break, late, 10 punches.
        ['emp_id' => '17', 'name' => 'Yogesh', 'first_punch' => '10:31:00', 'last_punch' => '17:40:00',
            'working_min' => 273, 'break_min' => 157, 'overtime_min' => 0, 'late' => true, 'delay_min' => 1,
            'punch_count' => 10, 'status' => 'Completed Shift'],
        // Unknown PIN with no matching HRMS employee — must be skipped.
        ['emp_id' => '99', 'name' => 'Ghost', 'first_punch' => '09:00:00', 'last_punch' => '18:00:00',
            'working_min' => 480, 'break_min' => 60, 'overtime_min' => 0, 'late' => false, 'delay_min' => 0,
            'punch_count' => 2, 'status' => 'Completed Shift'],
    ]);

    $this->artisan('attendance:sync-engine', ['--date' => '2026-06-29'])
        ->expectsOutputToContain('synced 1, skipped 1')
        ->assertSuccessful();

    expect(AttendanceDailySummary::count())->toBe(1);

    $row = AttendanceDailySummary::where('employee_id', $yogesh->id)->first();
    expect($row->employee_code)->toBe(17);
    expect($row->working_hours)->toBe('4.55');          // 273 min / 60
    expect($row->break_minutes)->toBe(157);
    expect($row->late_minutes)->toBe(1);
    expect($row->status)->toBe('late');                 // late flag → 'late'
    expect($row->raw_punch_count)->toBe(10);
    expect($row->first_punch->format('Y-m-d H:i:s'))->toBe('2026-06-29 10:31:00');
    expect($row->last_punch->format('H:i:s'))->toBe('17:40:00');

    // It also writes the core attendances row so the standard pages show it.
    $att = Attendance::where('employee_id', $yogesh->id)->where('date', '2026-06-29')->first();
    expect($att)->not->toBeNull();
    expect($att->check_in->format('H:i:s'))->toBe('10:31:00');
    expect($att->check_out->format('H:i:s'))->toBe('17:40:00');
    expect((float) $att->total_hours)->toBe(4.55);
    expect($att->break_minutes)->toBe(157);
    expect($att->status)->toBe('late');
    expect($att->is_late)->toBeTrue();
    expect($att->late_minutes)->toBe(1);
});

test('re-running the sync overwrites the same day (idempotent)', function () {
    $yogesh = Employee::factory()->create(['employee_code' => 17, 'manager_id' => null]);

    // Two runs, two different engine snapshots (later punch → more hours).
    Http::fake(['*/api/dashboard*' => Http::sequence()
        ->push(['table' => [['emp_id' => '17', 'first_punch' => '10:31:00', 'last_punch' => '17:40:00',
            'working_min' => 273, 'break_min' => 157, 'overtime_min' => 0, 'late' => true, 'delay_min' => 1,
            'punch_count' => 10, 'status' => 'Completed Shift']]], 200)
        ->push(['table' => [['emp_id' => '17', 'first_punch' => '10:31:00', 'last_punch' => '19:40:00',
            'working_min' => 540, 'break_min' => 60, 'overtime_min' => 60, 'late' => true, 'delay_min' => 1,
            'punch_count' => 12, 'status' => 'Completed Shift']]], 200),
    ]);

    $this->artisan('attendance:sync-engine', ['--date' => '2026-06-29'])->assertSuccessful();
    $this->artisan('attendance:sync-engine', ['--date' => '2026-06-29'])->assertSuccessful();

    expect(AttendanceDailySummary::where('employee_id', $yogesh->id)->count())->toBe(1);
    expect(AttendanceDailySummary::where('employee_id', $yogesh->id)->first()->working_hours)->toBe('9.00');
});

test('it fails cleanly when the engine is unreachable', function () {
    Employee::factory()->create(['employee_code' => 17, 'manager_id' => null]);

    Http::fake(['*/api/dashboard*' => Http::response('error', 500)]);

    $this->artisan('attendance:sync-engine')->assertFailed();

    expect(AttendanceDailySummary::count())->toBe(0);
});

test('it records the punch verification method (face in, card out)', function () {
    $emp = Employee::factory()->create(['employee_code' => 21, 'manager_id' => null]);

    fakeDashboard([
        ['emp_id' => '21', 'name' => 'Mia', 'first_punch' => '09:02:00', 'last_punch' => '18:05:00',
            'first_punch_method' => 'face', 'last_punch_method' => 'id_card',
            'working_min' => 480, 'break_min' => 60, 'overtime_min' => 0, 'late' => false, 'delay_min' => 0,
            'punch_count' => 6, 'status' => 'Completed Shift'],
    ]);

    $this->artisan('attendance:sync-engine', ['--date' => '2026-06-29'])->assertSuccessful();

    $summary = AttendanceDailySummary::where('employee_id', $emp->id)->first();
    expect($summary->first_punch_method)->toBe('face');
    expect($summary->last_punch_method)->toBe('id_card');

    $att = Attendance::where('employee_id', $emp->id)->where('date', '2026-06-29')->first();
    expect($att->check_in_method)->toBe('face');
    expect($att->check_out_method)->toBe('id_card');
});

test('an unsupported verify mode is stored as null rather than a bad chip', function () {
    $emp = Employee::factory()->create(['employee_code' => 22, 'manager_id' => null]);

    fakeDashboard([
        ['emp_id' => '22', 'name' => 'Sam', 'first_punch' => '09:00:00', 'last_punch' => '18:00:00',
            'first_punch_method' => 'fingerprint', 'last_punch_method' => '1',
            'working_min' => 480, 'break_min' => 0, 'overtime_min' => 0, 'late' => false, 'delay_min' => 0,
            'punch_count' => 2, 'status' => 'Completed Shift'],
    ]);

    $this->artisan('attendance:sync-engine', ['--date' => '2026-06-29'])->assertSuccessful();

    $att = Attendance::where('employee_id', $emp->id)->first();
    expect($att->check_in_method)->toBeNull();
    expect($att->check_out_method)->toBeNull();
});

test('a backfill syncs every date in the --from/--to range', function () {
    $emp = Employee::factory()->create(['employee_code' => 31, 'manager_id' => null]);

    Http::fake(function ($request) {
        return Http::response(['table' => [
            ['emp_id' => '31', 'first_punch' => '09:00:00', 'last_punch' => '18:00:00',
                'working_min' => 480, 'break_min' => 0, 'overtime_min' => 0, 'late' => false, 'delay_min' => 0,
                'punch_count' => 2, 'status' => 'Completed Shift'],
        ]], 200);
    });

    $this->artisan('attendance:sync-engine', ['--from' => '2026-06-27', '--to' => '2026-06-29'])
        ->assertSuccessful();

    expect(AttendanceDailySummary::where('employee_id', $emp->id)->count())->toBe(3);
    expect(Attendance::where('employee_id', $emp->id)->count())->toBe(3);
});

test('a backfill continues past a failed day instead of aborting', function () {
    $emp = Employee::factory()->create(['employee_code' => 32, 'manager_id' => null]);

    Http::fake(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
        if (($q['date'] ?? '') === '2026-06-28') {
            return Http::response('boom', 500);
        }

        return Http::response(['table' => [
            ['emp_id' => '32', 'first_punch' => '09:00:00', 'last_punch' => '18:00:00',
                'working_min' => 480, 'punch_count' => 2, 'status' => 'Completed Shift'],
        ]], 200);
    });

    $this->artisan('attendance:sync-engine', ['--from' => '2026-06-27', '--to' => '2026-06-29'])
        ->assertSuccessful();

    // 27th and 29th synced; 28th skipped because the engine errored that day.
    expect(AttendanceDailySummary::where('employee_id', $emp->id)->count())->toBe(2);
});

test('a backfill with start after end fails cleanly', function () {
    Http::fake(['*/api/dashboard*' => Http::response(['table' => []], 200)]);

    $this->artisan('attendance:sync-engine', ['--from' => '2026-06-29', '--to' => '2026-06-27'])
        ->assertFailed();
});

test('a resilient rolling run continues past a failed day', function () {
    $emp = Employee::factory()->create(['employee_code' => 34, 'manager_id' => null]);

    Http::fake(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
        if (($q['date'] ?? '') === now()->toDateString()) {
            return Http::response('down', 500); // today fails
        }

        return Http::response(['table' => [
            ['emp_id' => '34', 'first_punch' => '09:00:00', 'last_punch' => '18:00:00',
                'working_min' => 480, 'punch_count' => 2, 'status' => 'Completed Shift'],
        ]], 200);
    });

    // Without --resilient this would abort on today; with it, the older days sync.
    $this->artisan('attendance:sync-engine', ['--days' => 3, '--resilient' => true])
        ->assertSuccessful();

    expect(AttendanceDailySummary::where('employee_id', $emp->id)->count())->toBe(2);
});
