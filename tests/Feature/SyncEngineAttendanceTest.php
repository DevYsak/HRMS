<?php

use App\Models\Attendance;
use App\Models\AttendanceDailySummary;
use App\Models\AttendancePunch;
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
            'first_punch_method' => 'password', 'last_punch_method' => '0',
            'working_min' => 480, 'break_min' => 0, 'overtime_min' => 0, 'late' => false, 'delay_min' => 0,
            'punch_count' => 2, 'status' => 'Completed Shift'],
    ]);

    $this->artisan('attendance:sync-engine', ['--date' => '2026-06-29'])->assertSuccessful();

    $att = Attendance::where('employee_id', $emp->id)->first();
    expect($att->check_in_method)->toBeNull();
    expect($att->check_out_method)->toBeNull();
});

test('the engine break_min and working_min are trusted, never re-derived from HRMS punches', function () {
    $emp = Employee::factory()->create(['employee_code' => 77, 'manager_id' => null]);

    // The real engine pairs device IN/OUT direction, so it correctly reports an
    // 18-minute break over this noisy stream (duplicate reads at 13:30). HRMS's
    // own time-based dedup would mis-pair this to a large phantom break — so it
    // must NOT re-derive; the engine's figures stand.
    $punchTimes = ['10:19:28', '12:56:29', '12:58:41', '13:19:11', '13:30:30', '13:30:33', '13:30:35', '15:02:45', '15:04:53', '16:34:25'];

    Http::fake(fn () => Http::response(['table' => [[
        'emp_id' => '77', 'first_punch' => '10:19:28', 'last_punch' => '16:34:25',
        'working_min' => 359, 'break_min' => 18, 'overtime_min' => 0, 'late' => false, 'delay_min' => 0,
        'inside' => false, 'punch_count' => count($punchTimes), 'status' => 'Completed Shift',
        'punches' => array_map(fn ($t) => ['time' => $t, 'verify' => 'face'], $punchTimes),
    ]]], 200));

    $this->artisan('attendance:sync-engine', ['--date' => '2026-06-29'])->assertSuccessful();

    $att = Attendance::where('employee_id', $emp->id)->first();
    expect($att->break_minutes)->toBe(18)               // engine, not a re-derived phantom
        ->and((float) $att->total_hours)->toBe(5.98);   // 359 min / 60

    expect(AttendanceDailySummary::where('employee_id', $emp->id)->first()->break_minutes)->toBe(18);
});

test('a still-inside employee keeps check_out open (last punch is a live IN)', function () {
    $emp = Employee::factory()->create(['employee_code' => 16, 'manager_id' => null]);

    fakeDashboard([
        ['emp_id' => '16', 'name' => 'Mayuresh', 'first_punch' => '10:19:28', 'last_punch' => '16:36:20',
            'working_min' => 359, 'break_min' => 18, 'overtime_min' => 0, 'late' => false, 'delay_min' => 0,
            'inside' => true, 'punch_count' => 11, 'status' => 'Inside Office'],
    ]);

    $this->artisan('attendance:sync-engine', ['--date' => '2026-06-29'])->assertSuccessful();

    $att = Attendance::where('employee_id', $emp->id)->first();
    expect($att->check_in->format('H:i:s'))->toBe('10:19:28')
        ->and($att->check_out)->toBeNull();             // still inside → no clock-out

    // The summary still records the last punch for reference.
    $summary = AttendanceDailySummary::where('employee_id', $emp->id)->first();
    expect($summary->last_punch->format('H:i:s'))->toBe('16:36:20')
        ->and($summary->working_hours)->toBe('5.98')
        ->and($summary->break_minutes)->toBe(18);
});

test('when the engine sends no totals, break/working fall back to the punch stream', function () {
    $emp = Employee::factory()->create(['employee_code' => 78, 'manager_id' => null]);

    // Legacy/degraded engine row: no working_min or break_min keys at all, but a
    // clean two-session punch stream (one 30-minute break). HRMS derives it.
    $punchTimes = ['09:00:00', '12:00:00', '12:30:00', '18:00:00'];

    Http::fake(fn () => Http::response(['table' => [[
        'emp_id' => '78', 'first_punch' => '09:00:00', 'last_punch' => '18:00:00',
        'late' => false, 'delay_min' => 0, 'punch_count' => count($punchTimes), 'status' => 'Completed Shift',
        'punches' => array_map(fn ($t) => ['time' => $t, 'verify' => 'face'], $punchTimes),
    ]]], 200));

    $this->artisan('attendance:sync-engine', ['--date' => '2026-06-29'])->assertSuccessful();

    $att = Attendance::where('employee_id', $emp->id)->first();
    // gross 09:00→18:00 = 540m, minus a derived 30m break = 510m → 8.50h.
    expect($att->break_minutes)->toBe(30)
        ->and((float) $att->total_hours)->toBe(8.5);
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

test('the engine pull maps raw device verify codes via the device map', function () {
    // AIFACE-MAGNUM (confirmed live): 15 = Face, 4 = Card. Engine sends raw verify.
    config(['biometric.verify_methods' => [1 => 'fingerprint', 4 => 'id_card', 15 => 'face']]);
    $emp = Employee::factory()->create(['employee_code' => 41, 'manager_id' => null]);

    fakeDashboard([
        ['emp_id' => '41', 'first_punch' => '09:00:00', 'last_punch' => '18:00:00',
            'first_punch_method' => 15, 'last_punch_method' => 4,
            'working_min' => 480, 'punch_count' => 2, 'status' => 'Completed Shift'],
    ]);

    $this->artisan('attendance:sync-engine', ['--date' => '2026-06-29'])->assertSuccessful();

    $att = Attendance::where('employee_id', $emp->id)->first();
    expect($att->check_in_method)->toBe('face');    // code 15 → Face on this device
    expect($att->check_out_method)->toBe('id_card'); // code 4 → Card
});

test('the engine pull ingests every individual punch into the journey', function () {
    config(['biometric.verify_methods' => [1 => 'fingerprint', 4 => 'id_card', 15 => 'face']]);
    $emp = Employee::factory()->create(['employee_code' => 51, 'manager_id' => null]);

    fakeDashboard([
        ['emp_id' => '51', 'first_punch' => '09:02:00', 'last_punch' => '18:15:00',
            'working_min' => 480, 'punch_count' => 4, 'status' => 'Completed Shift',
            'punches' => [
                ['time' => '09:02:00', 'verify' => 15],   // face in
                ['time' => '13:05:00', 'verify' => 4],    // card out (break)
                ['time' => '13:40:00', 'verify' => 4],    // card in (return)
                ['time' => '18:15:00', 'verify' => 15],   // face out
            ]],
    ]);

    $this->artisan('attendance:sync-engine', ['--date' => '2026-06-29'])->assertSuccessful();

    $punches = AttendancePunch::where('employee_id', $emp->id)->orderBy('punched_at')->get();
    expect($punches)->toHaveCount(4);
    expect($punches->first()->method)->toBe('face');
    expect($punches->get(1)->method)->toBe('id_card');
    expect($punches->last()->method)->toBe('face');
    expect($punches->first()->punched_at->format('Y-m-d H:i'))->toBe('2026-06-29 09:02');
});

test('re-syncing punches is idempotent (no duplicates)', function () {
    $emp = Employee::factory()->create(['employee_code' => 52, 'manager_id' => null]);

    fakeDashboard([
        ['emp_id' => '52', 'first_punch' => '09:00:00', 'last_punch' => '18:00:00',
            'working_min' => 480, 'punch_count' => 2, 'status' => 'Completed Shift',
            'punches' => [['time' => '09:00:00', 'verify' => 15], ['time' => '18:00:00', 'verify' => 15]]],
    ]);

    $this->artisan('attendance:sync-engine', ['--date' => '2026-06-29'])->assertSuccessful();
    $this->artisan('attendance:sync-engine', ['--date' => '2026-06-29'])->assertSuccessful();

    expect(AttendancePunch::where('employee_id', $emp->id)->count())->toBe(2);
});
