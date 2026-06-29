<?php

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
